<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Target;

use App\Module\Core\Application\Backup\BackupOperationHeartbeat;
use App\Module\Core\Application\Backup\BackupRun;
use App\Module\Core\Application\Backup\BackupStorageTarget;
use App\Module\Core\Application\Backup\RemoteArchiveProof;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/** SMB transfers use signing and a private authentication file, never shell commands. */
final class SmbBackupTargetWriter implements BackupArchiveStorageInterface
{
    public function __construct(private readonly ?BackupOperationHeartbeat $lease = null)
    {
    }

    #[\Override]
    public function supports(BackupStorageTarget $target): bool
    {
        return 'smb' === $target->type();
    }

    #[\Override]
    public function write(BackupStorageTarget $target, string $archiveName, string $sourceFile): string
    {
        self::assertName($archiveName);
        self::assertArgument($sourceFile);
        $directory = $this->directory($target);
        if ('' !== $directory) {
            $prefix = '';
            foreach (explode('/', $directory) as $segment) {
                $prefix .= '/'.$segment;
                try {
                    // A standalone cd verifies existence without issuing destructive
                    // commands after a failed directory change. allinfo additionally
                    // requests optional shadow-copy metadata and can fail on valid dirs.
                    $this->execute($target, 'cd '.self::quote(str_replace('/', '\\', $prefix)));
                } catch (\RuntimeException) {
                    $this->execute($target, 'mkdir '.self::quote(str_replace('/', '\\', $prefix)));
                }
            }
        }
        $temporary = '.easywi-upload-'.bin2hex(random_bytes(16));
        try {
            $this->execute($target, 'put '.self::quote($sourceFile).' '.self::quote($this->remoteFile($target, $temporary)));
            $this->execute($target, 'rename '.self::quote($this->remoteFile($target, $temporary)).' '.self::quote($this->remoteFile($target, $archiveName)));
        } catch (\Throwable $error) {
            try {
                $this->execute($target, 'del '.self::quote($this->remoteFile($target, $temporary)));
            } catch (\Throwable) {
                // Preserve the original upload failure; an incomplete staging file is never recorded.
            }
            throw $error;
        }

        return $this->location($target, $archiveName);
    }

    #[\Override]
    public function inspect(BackupStorageTarget $target, string $location, callable $heartbeat): RemoteArchiveProof
    {
        return $this->inspectName($target, $this->nameAt($target, $location), $heartbeat);
    }

    #[\Override]
    public function delete(BackupStorageTarget $target, BackupRun $run, RemoteArchiveProof $proof, callable $heartbeat): void
    {
        $name = $this->nameAt($target, $run->archivePath());
        if ($proof->size !== $run->sizeBytes() || !hash_equals($run->checksumSha256(), $proof->sha256)) {
            throw new \RuntimeException('SMB deletion requires a matching verified archive.');
        }
        if ($this->inspectName($target, $name, $heartbeat) != $proof) {
            throw new \RuntimeException('SMB archive changed before deletion.');
        }
        $quarantine = '.easywi-retention-'.bin2hex(random_bytes(16));
        $this->execute($target, 'rename '.self::quote($this->remoteFile($target, $name)).' '.self::quote($this->remoteFile($target, $quarantine)), $heartbeat);
        try {
            if ($this->inspectName($target, $quarantine, $heartbeat) != $proof) {
                throw new \RuntimeException('SMB archive changed while being isolated.');
            }
            $this->execute($target, 'del '.self::quote($this->remoteFile($target, $quarantine)), $heartbeat);
        } catch (\Throwable $error) {
            try {
                // SMB rename refuses to replace a file that appeared at the old name.
                $this->execute($target, 'rename '.self::quote($this->remoteFile($target, $quarantine)).' '.self::quote($this->remoteFile($target, $name)));
                $message = 'SMB retention stopped; original archive restored.';
            } catch (\Throwable) {
                $message = 'SMB retention stopped; isolated archive retained at '.$this->location($target, $quarantine).'.';
            }
            throw new \RuntimeException($message, 0, $error);
        }
    }

    /** @param callable():void $heartbeat */
    private function inspectName(BackupStorageTarget $target, string $name, callable $heartbeat): RemoteArchiveProof
    {
        $temporary = tempnam(sys_get_temp_dir(), 'easywi-smb-verify-');
        if (false === $temporary) {
            throw new \RuntimeException('Cannot stage SMB archive verification.');
        }
        try {
            if (!chmod($temporary, 0600)) {
                throw new \RuntimeException('Cannot protect SMB verification file.');
            }
            $this->execute($target, 'get '.self::quote($this->remoteFile($target, $name)).' '.self::quote($temporary), $heartbeat);
            $proof = VerifiedArchiveFile::inspect($temporary, $heartbeat);

            return new RemoteArchiveProof($proof->size, $proof->sha256, $proof->sha256.':'.$proof->size);
        } finally {
            @unlink($temporary);
        }
    }

    /** @param (callable():void)|null $heartbeat */
    private function execute(BackupStorageTarget $target, string $command, ?callable $heartbeat = null): void
    {
        $host = self::text($target->config()['host'] ?? '');
        $share = self::text($target->config()['share'] ?? '');
        self::assertArgument($host);
        self::assertArgument($share);
        if ('' === $host || '' === $share || false !== strpbrk($host.$share, '/\\') || str_starts_with($host, '-')) {
            throw new \InvalidArgumentException('SMB requires a valid host and share.');
        }
        $username = self::text($target->secrets()['username'] ?? $target->config()['username'] ?? '');
        $password = self::text($target->secrets()['password'] ?? '');
        if ('' === $username || false !== strpbrk($username.$password, "\r\n\0")) {
            throw new \InvalidArgumentException('SMB requires valid credentials without line breaks.');
        }
        $binary = (new ExecutableFinder())->find('smbclient');
        if (null === $binary) {
            throw new \RuntimeException('smbclient binary not found. Install the samba-client package.');
        }
        $authFile = tempnam(sys_get_temp_dir(), 'smb_auth_');
        if (false === $authFile) {
            throw new \RuntimeException('Cannot create SMB authentication file.');
        }
        $process = null;
        try {
            if (!chmod($authFile, 0600) || false === file_put_contents($authFile, 'username = '.$username."\npassword = ".$password."\n")) {
                throw new \RuntimeException('Cannot protect SMB authentication file.');
            }
            if (null !== $heartbeat) {
                $heartbeat();
            }
            $arguments = [$binary, '//'.$host.'/'.$share, '-A', $authFile, '--option=client signing=required'];
            if (isset($target->config()['port'])) {
                $port = filter_var($target->config()['port'], FILTER_VALIDATE_INT);
                if (!is_int($port) || $port < 1 || $port > 65535) {
                    throw new \InvalidArgumentException('Invalid SMB server port.');
                }
                $arguments[] = '-p';
                $arguments[] = (string) $port;
            }
            array_push($arguments, '-c', $command);
            $process = new Process($arguments, timeout: 900);
            $this->lease?->pulse(true);
            $process->start();
            $nextHeartbeat = microtime(true) + 1.0;
            while ($process->isRunning()) {
                $this->lease?->pulse();
                if (null !== $heartbeat && microtime(true) >= $nextHeartbeat) {
                    $heartbeat();
                    $nextHeartbeat = microtime(true) + 1.0;
                }
                $process->checkTimeout();
                usleep(100000);
            }
            // smbclient can report a failed sub-command even when its process exits zero.
            $output = $process->getOutput().' '.$process->getErrorOutput();
            if (!$process->isSuccessful() || 1 === preg_match('/\bNT_STATUS_[A-Z_]+\b/', $output)) {
                throw new \RuntimeException('smbclient operation failed (exit '.($process->getExitCode() ?? 1).').');
            }
        } finally {
            if (null !== $process && $process->isRunning()) {
                $process->stop();
            }
            @unlink($authFile);
        }
    }

    private function directory(BackupStorageTarget $target): string
    {
        $path = trim(str_replace('\\', '/', self::text($target->config()['path'] ?? '')), '/');
        self::assertArgument($path);
        foreach (explode('/', $path) as $segment) {
            if ('.' === $segment || '..' === $segment) {
                throw new \InvalidArgumentException('Invalid SMB backup directory.');
            }
        }

        return $path;
    }

    private function remoteFile(BackupStorageTarget $target, string $name): string
    {
        self::assertName($name);
        $directory = $this->directory($target);

        return ('' === $directory ? '' : '\\'.str_replace('/', '\\', $directory).'\\').$name;
    }

    private function location(BackupStorageTarget $target, string $name): string
    {
        self::assertName($name);
        $directory = $this->directory($target);

        return 'smb://'.self::text($target->config()['host'] ?? '').'/'.self::text($target->config()['share'] ?? '').'/'.('' === $directory ? '' : $directory.'/').$name;
    }

    private function nameAt(BackupStorageTarget $target, string $location): string
    {
        $name = basename($location);
        if ($this->location($target, $name) !== $location) {
            throw new \RuntimeException('SMB archive is outside the configured target directory.');
        }

        return $name;
    }

    private static function quote(string $value): string
    {
        self::assertArgument($value);

        return '"'.$value.'"';
    }

    private static function assertName(string $name): void
    {
        self::assertArgument($name);
        if ('' === $name || '.' === $name || '..' === $name || false !== strpbrk($name, '/\\')) {
            throw new \InvalidArgumentException('Invalid SMB archive name.');
        }
    }

    private static function assertArgument(string $value): void
    {
        if (false !== strpbrk($value, "\"';\r\n\0")) {
            throw new \InvalidArgumentException('SMB path contains unsupported command delimiters.');
        }
    }

    private static function text(mixed $value): string
    {
        if (null === $value || is_scalar($value)) {
            return (string) $value;
        }

        throw new \InvalidArgumentException('Backup target field must be scalar.');
    }
}
