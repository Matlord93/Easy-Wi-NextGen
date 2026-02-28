<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Target;

use App\Module\Core\Application\Backup\BackupStorageTarget;

final class LocalBackupTargetWriter implements BackupTargetWriterInterface
{
    public function supports(BackupStorageTarget $target): bool
    {
        return $target->type() === 'local';
    }

    public function write(BackupStorageTarget $target, string $archiveName, string $sourceFile): string
    {
        $basePath = (string) ($target->config()['path'] ?? '');
        if ($basePath === '') {
            throw new \InvalidArgumentException('Local backup target requires path config.');
        }

        if (!is_dir($basePath) && !mkdir($basePath, 0775, true) && !is_dir($basePath)) {
            throw new \RuntimeException('Failed to create local backup destination directory.');
        }

        $destination = rtrim($basePath, '/').'/'.$archiveName;
        if (!copy($sourceFile, $destination)) {
            throw new \RuntimeException('Failed to persist backup archive to local target.');
        }

        return $destination;
    }
}
