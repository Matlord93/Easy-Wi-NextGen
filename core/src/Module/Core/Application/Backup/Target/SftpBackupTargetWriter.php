<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Target;

use App\Infrastructure\Security\PrivateKeyFileReader;
use App\Module\Core\Application\Backup\BackupOperationHeartbeat;
use App\Module\Core\Application\Backup\BackupRun;
use App\Module\Core\Application\Backup\BackupStorageTarget;
use App\Module\Core\Application\Backup\RemoteArchiveProof;
use App\Module\Core\Application\SftpConnectionFactory;
use App\Module\Core\Application\SshHostKeyVerifier;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

/** SFTP is also the safe transport for SSH targets; no remote shell is involved. */
final readonly class SftpBackupTargetWriter implements BackupArchiveStorageInterface
{
    public function __construct(private SftpConnectionFactory $connections, private SshHostKeyVerifier $hostKeys, private PrivateKeyFileReader $keys, private ?BackupOperationHeartbeat $lease = null)
    {
    }

    #[\Override]
    public function supports(BackupStorageTarget $target): bool
    {
        return in_array($target->type(), ['sftp', 'ssh'], true);
    }

    #[\Override]
    public function write(BackupStorageTarget $target, string $archiveName, string $sourceFile): string
    {
        self::assertName($archiveName);
        if (!is_file($sourceFile) || !is_readable($sourceFile)) {
            throw new \InvalidArgumentException('Backup source file is not readable.');
        }
        $connection = $this->connect($target);
        try {
            $this->lease?->pulse(true);
            $root = $this->root($connection, $target, true);
            $temporary = $root.'/.easywi-upload-'.bin2hex(random_bytes(16));
            $destination = $root.'/'.$archiveName;
            try {
                if (!$connection->put($temporary, $sourceFile, SFTP::SOURCE_LOCAL_FILE, -1, -1, function (): void { $this->lease?->pulse(); }) || !$connection->chmod(0600, $temporary)) {
                    throw new \RuntimeException('SFTP archive upload failed.');
                }
                $this->lease?->pulse(true);
                // Standard SFTP rename refuses to overwrite an existing archive.
                if (!$connection->rename($temporary, $destination)) {
                    throw new \RuntimeException('SFTP archive publication failed; destination may already exist.');
                }
            } finally {
                $connection->delete($temporary, false);
            }
        } finally {
            $connection->disconnect();
        }

        return $this->location($target, $archiveName);
    }

    #[\Override]
    public function inspect(BackupStorageTarget $target, string $location, callable $heartbeat): RemoteArchiveProof
    {
        $name = $this->nameAt($target, $location);
        $heartbeat();
        $connection = $this->connect($target);
        try {
            $root = $this->root($connection, $target);

            return $this->inspectFile($connection, $root.'/'.$name, $heartbeat);
        } finally {
            $connection->disconnect();
        }
    }

    #[\Override]
    public function delete(BackupStorageTarget $target, BackupRun $run, RemoteArchiveProof $proof, callable $heartbeat): void
    {
        $name = $this->nameAt($target, $run->archivePath());
        if ($proof->size !== $run->sizeBytes() || !hash_equals($run->checksumSha256(), $proof->sha256)) {
            throw new \RuntimeException('SFTP deletion requires a matching verified archive.');
        }
        $heartbeat();
        $connection = $this->connect($target);
        try {
            $root = $this->root($connection, $target);
            $path = $root.'/'.$name;
            if ($this->inspectFile($connection, $path, $heartbeat) != $proof) {
                throw new \RuntimeException('SFTP archive changed before deletion.');
            }
            $quarantine = $root.'/.easywi-retention-'.bin2hex(random_bytes(16));
            $heartbeat();
            if (!$connection->rename($path, $quarantine)) {
                throw new \RuntimeException('SFTP archive cannot be isolated for deletion.');
            }
            try {
                if ($this->inspectFile($connection, $quarantine, $heartbeat) != $proof) {
                    throw new \RuntimeException('SFTP archive changed while being isolated.');
                }
                $heartbeat();
                if (!$connection->delete($quarantine, false)) {
                    throw new \RuntimeException('SFTP archive deletion failed.');
                }
            } catch (\Throwable $error) {
                // rename() never replaces a new file at the original path.
                try {
                    $restored = $connection->rename($quarantine, $path);
                } catch (\Throwable) {
                    $restored = false;
                }
                throw new \RuntimeException($restored ? 'SFTP retention stopped; original archive restored.' : 'SFTP retention stopped; isolated archive retained at '.$quarantine.'.', 0, $error);
            }
        } finally {
            $connection->disconnect();
        }
    }

    private function connect(BackupStorageTarget $target): SFTP
    {
        $host = self::text($target->config()['host'] ?? '');
        $port = filter_var($target->config()['port'] ?? 22, FILTER_VALIDATE_INT);
        $fingerprint = self::text($target->config()['host_key_fingerprint'] ?? '');
        $this->hostKeys->requireFingerprint($fingerprint);
        if ('' === $host || false !== strpbrk($host, "/\\\0\r\n") || !is_int($port) || $port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('SFTP requires a valid host and port.');
        }
        $connection = $this->connections->create($host, $port);
        try {
            $this->hostKeys->verify($connection, $fingerprint);
            $username = self::text($target->secrets()['username'] ?? $target->config()['username'] ?? '');
            $keyPath = self::text($target->secrets()['private_key_path'] ?? $target->config()['private_key_path'] ?? '');
            $credential = '' !== $keyPath
                ? PublicKeyLoader::loadPrivateKey($this->keys->read($keyPath), self::text($target->secrets()['private_key_passphrase'] ?? ''))
                : self::text($target->secrets()['password'] ?? '');
            if ('' === $username || !$connection->login($username, $credential)) {
                throw new \RuntimeException('SFTP backup authentication failed.');
            }
            $connection->disableStatCache();

            return $connection;
        } catch (\Throwable $error) {
            $connection->disconnect();
            throw $error;
        }
    }

    private function root(SFTP $connection, BackupStorageTarget $target, bool $create = false): string
    {
        $root = $this->configuredRoot($target);
        if ($create && !$connection->is_dir($root) && !$connection->mkdir($root, 0700, true)) {
            throw new \RuntimeException('SFTP backup directory cannot be created.');
        }
        $stat = $connection->lstat($root);
        if (!is_array($stat) || ($stat['type'] ?? null) !== 2 || $connection->realpath($root) !== $root) {
            throw new \RuntimeException('SFTP target must resolve to the configured directory without symbolic links.');
        }

        return $root;
    }

    /** @param callable():void $heartbeat */
    private function inspectFile(SFTP $connection, string $path, callable $heartbeat): RemoteArchiveProof
    {
        $before = $connection->lstat($path);
        if (!is_array($before) || ($before['type'] ?? null) !== 1 || $connection->realpath($path) !== $path) {
            throw new \RuntimeException('SFTP archive is missing, not regular, or symbolic.');
        }
        $hash = hash_init('sha256');
        $size = 0;
        $heartbeat();
        $success = $connection->get($path, static function (string $chunk) use ($hash, &$size, $heartbeat): void {
            $heartbeat();
            hash_update($hash, $chunk);
            $size += strlen($chunk);
        });
        $after = $connection->lstat($path);
        // phpseclib returns an empty string after a successful callback transfer.
        if (false === $success || !is_array($after) || $this->statIdentity($before) !== $this->statIdentity($after)
            || ($after['size'] ?? null) !== $size) {
            throw new \RuntimeException('SFTP archive changed or could not be completely verified.');
        }
        $heartbeat();

        return new RemoteArchiveProof($size, hash_final($hash), $this->statIdentity($after));
    }

    /** @param array<array-key,mixed> $stat */
    private function statIdentity(array $stat): string
    {
        $identity = [];
        foreach (['type', 'size', 'mtime', 'mode', 'uid', 'gid'] as $key) {
            $identity[$key] = self::statNumber($stat[$key] ?? null);
        }

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }

    private static function statNumber(mixed $value): ?int
    {
        if (null !== $value && !is_int($value)) {
            throw new \RuntimeException('Invalid numeric SFTP archive metadata.');
        }

        return $value;
    }

    private function configuredRoot(BackupStorageTarget $target): string
    {
        $root = rtrim(self::text($target->config()['path'] ?? $target->config()['base_path'] ?? ''), '/');
        if ('' === $root || !str_starts_with($root, '/') || false !== strpbrk($root, "\\\0\r\n")) {
            throw new \InvalidArgumentException('SFTP requires an absolute dedicated backup directory.');
        }
        foreach (explode('/', $root) as $segment) {
            if ('.' === $segment || '..' === $segment) {
                throw new \InvalidArgumentException('Invalid SFTP backup directory.');
            }
        }

        return $root;
    }

    private function location(BackupStorageTarget $target, string $name): string
    {
        self::assertName($name);
        $port = self::text($target->config()['port'] ?? 22);
        $host = self::text($target->config()['host'] ?? '');
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '['.$host.']';
        }

        return $target->type().'://'.$host.':'.$port.$this->configuredRoot($target).'/'.$name;
    }

    private function nameAt(BackupStorageTarget $target, string $location): string
    {
        $name = basename($location);
        if ($this->location($target, $name) !== $location) {
            throw new \RuntimeException('SFTP archive is outside the configured target directory.');
        }

        return $name;
    }

    private static function assertName(string $name): void
    {
        if ('' === $name || '.' === $name || '..' === $name || false !== strpbrk($name, "/\\\0\r\n")) {
            throw new \InvalidArgumentException('Invalid SFTP archive name.');
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
