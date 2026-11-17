<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Target;

use App\Module\Core\Application\Backup\BackupOperationHeartbeat;
use App\Module\Core\Application\Backup\BackupRun;
use App\Module\Core\Application\Backup\BackupStorageTarget;
use App\Module\Core\Application\Backup\RemoteArchiveProof;

/** Operates only on an existing, verified NFS/NFS4 mount; never falls back to local disk. */
final class NfsBackupTargetWriter implements BackupArchiveStorageInterface
{
    public function __construct(private readonly ?BackupOperationHeartbeat $lease = null)
    {
    }

    #[\Override]
    public function supports(BackupStorageTarget $target): bool
    {
        return 'nfs' === $target->type();
    }

    #[\Override]
    public function write(BackupStorageTarget $target, string $archiveName, string $sourceFile): string
    {
        $root = $this->root($target);
        $identity = self::mountIdentity($root);
        $result = (new LocalBackupTargetWriter($this->lease))->write(new BackupStorageTarget('local', $target->label(), ['path' => $root]), $archiveName, $sourceFile);
        if (self::mountIdentity($root) !== $identity) {
            throw new \RuntimeException('NFS mount changed during archive publication.');
        }

        return $result;
    }

    #[\Override]
    public function inspect(BackupStorageTarget $target, string $location, callable $heartbeat): RemoteArchiveProof
    {
        $root = $this->root($target);
        $this->assertLocation($root, $location);
        $identity = self::mountIdentity($root);
        $proof = VerifiedArchiveFile::inspect($location, $heartbeat);
        if (self::mountIdentity($root) !== $identity) {
            throw new \RuntimeException('NFS mount changed during archive verification.');
        }

        return new RemoteArchiveProof($proof->size, $proof->sha256, $identity.':'.$proof->version);
    }

    #[\Override]
    public function delete(BackupStorageTarget $target, BackupRun $run, RemoteArchiveProof $proof, callable $heartbeat): void
    {
        $root = $this->root($target);
        $path = $run->archivePath();
        $this->assertLocation($root, $path);
        $heartbeat();
        $current = $this->inspect($target, $path, $heartbeat);
        if ($current != $proof || $proof->size !== $run->sizeBytes() || !hash_equals($run->checksumSha256(), $proof->sha256)) {
            throw new \RuntimeException('NFS archive changed before deletion.');
        }
        $quarantine = $root.'/.easywi-retention-'.bin2hex(random_bytes(16));
        if (!rename($path, $quarantine)) {
            throw new \RuntimeException('NFS archive cannot be isolated for deletion.');
        }
        try {
            $isolated = $this->inspect($target, $quarantine, $heartbeat);
            if ($isolated != $proof) {
                throw new \RuntimeException('NFS archive changed while being isolated.');
            }
            $heartbeat();
            if (!unlink($quarantine)) {
                throw new \RuntimeException('NFS archive deletion failed.');
            }
        } catch (\Throwable $error) {
            // link() restores without replacing a new archive created at the old name.
            if (is_file($quarantine) && !is_link($quarantine) && @link($quarantine, $path)) {
                @unlink($quarantine);
            }
            throw new \RuntimeException('NFS retention stopped; inspect the archive and possible quarantine '.$quarantine.'.', 0, $error);
        }
    }

    /** Returns the mounted server/export and filesystem identity, not just the directory name. */
    public static function mountIdentity(string $path): string
    {
        $real = realpath($path);
        $lines = @file('/proc/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (false === $real || '/' === $real || !is_dir($real) || false === $lines) {
            throw new \RuntimeException('Cannot verify the configured NFS mount.');
        }
        $matched = null;
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', $line);
            if (!is_array($parts) || count($parts) < 3) {
                continue;
            }
            $mount = self::unescapeMount($parts[1]);
            if ($real === $mount || str_starts_with($real, rtrim($mount, '/').'/')) {
                if (null === $matched || strlen($mount) > strlen($matched['mount'])) {
                    $matched = ['mount' => $mount, 'source' => self::unescapeMount($parts[0]), 'type' => $parts[2]];
                }
            }
        }
        if (null === $matched || !in_array($matched['type'], ['nfs', 'nfs4'], true)) {
            throw new \RuntimeException('Backup directory is not on an NFS/NFS4 mount.');
        }
        $stat = @stat($real);
        if (false === $stat) {
            throw new \RuntimeException('Cannot identify the configured NFS filesystem.');
        }

        return hash('sha256', json_encode([$real, $matched, $stat['dev']], JSON_THROW_ON_ERROR));
    }

    private static function unescapeMount(string $value): string
    {
        return str_replace(['\\040', '\\011', '\\012', '\\134'], [' ', "\t", "\n", '\\'], $value);
    }

    private function root(BackupStorageTarget $target): string
    {
        $path = $target->config()['path'] ?? $target->config()['base_path'] ?? null;
        if (!is_string($path) || !str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('NFS backup target requires an absolute mount path.');
        }
        self::mountIdentity($path);
        $root = realpath($path);
        if (false === $root) {
            throw new \RuntimeException('NFS directory cannot be resolved.');
        }

        return $root;
    }

    private function assertLocation(string $root, string $path): void
    {
        if (str_contains($path, "\0") || dirname($path) !== $root || '.' === basename($path) || '..' === basename($path)) {
            throw new \RuntimeException('NFS archive is outside the configured directory.');
        }
    }
}
