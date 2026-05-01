<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Target;

use App\Module\Core\Application\Backup\BackupStorageTarget;

/**
 * Writes backup archives to an NFS mount point.
 *
 * NFS shares must already be mounted on the agent host (e.g. via /etc/fstab).
 * This writer validates the path is a real mount and copies the file.
 *
 * Required target config keys:
 *   path – absolute local path of the NFS mount point (e.g. /mnt/nfs-backup)
 */
final class NfsBackupTargetWriter implements BackupTargetWriterInterface
{
    public function supports(BackupStorageTarget $target): bool
    {
        return $target->type() === 'nfs';
    }

    public function write(BackupStorageTarget $target, string $archiveName, string $sourceFile): string
    {
        $basePath = rtrim((string) ($target->config()['path'] ?? ''), '/');
        if ($basePath === '') {
            throw new \InvalidArgumentException('NFS backup target requires path config (local NFS mount point).');
        }

        if (!is_dir($basePath)) {
            throw new \RuntimeException(sprintf('NFS mount path "%s" does not exist or is not a directory.', $basePath));
        }

        // Verify the path is actually a mount (not an unmounted directory) by checking /proc/mounts.
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertIsMounted($basePath);
        }

        $destination = $basePath.'/'.$archiveName;

        if (!copy($sourceFile, $destination)) {
            throw new \RuntimeException(sprintf('Failed to copy backup archive to NFS target "%s".', $destination));
        }

        return $destination;
    }

    private function assertIsMounted(string $path): void
    {
        $mountsFile = '/proc/mounts';
        if (!is_readable($mountsFile)) {
            return; // Cannot verify — proceed anyway.
        }

        $realPath = realpath($path);
        if ($realPath === false) {
            return;
        }

        $mounts = file($mountsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($mounts === false) {
            return;
        }

        foreach ($mounts as $line) {
            $parts = preg_split('/\s+/', $line);
            if (!is_array($parts) || count($parts) < 3) {
                continue;
            }

            $mountPoint = $parts[1];
            $fsType     = strtolower($parts[2]);

            if (!in_array($fsType, ['nfs', 'nfs4', 'cifs'], true)) {
                continue;
            }

            if (str_starts_with($realPath, $mountPoint)) {
                return; // Found a matching NFS mount.
            }
        }

        throw new \RuntimeException(sprintf(
            'Path "%s" does not appear to be on an NFS mount. Verify the share is mounted before using this target.',
            $path,
        ));
    }
}
