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
        $basePath = (string) ($target->config()['base_path'] ?? $target->config()['path'] ?? '');
        if ($basePath === '' || !str_starts_with($basePath, '/')) {
            throw new \InvalidArgumentException('Local backup target requires an absolute path config.');
        }

        $basePath = rtrim(str_replace('\\', '/', $basePath), '/');
        $archiveName = basename(str_replace('\\', '/', $archiveName));
        if ($archiveName === '' || $archiveName === '.' || $archiveName === '..') {
            throw new \InvalidArgumentException('Invalid backup archive name.');
        }

        if (!is_file($sourceFile) || !is_readable($sourceFile)) {
            throw new \InvalidArgumentException('Backup source file is not readable.');
        }

        if (!is_dir($basePath) && !mkdir($basePath, 0775, true) && !is_dir($basePath)) {
            throw new \RuntimeException('Failed to create local backup destination directory.');
        }

        $realBase = realpath($basePath);
        if ($realBase === false) {
            throw new \RuntimeException('Failed to resolve local backup destination directory.');
        }

        $destination = $realBase.'/'.$archiveName;
        $destinationDir = dirname($destination);
        if ($destinationDir !== $realBase) {
            throw new \InvalidArgumentException('Local backup destination escaped the target directory.');
        }

        if (!copy($sourceFile, $destination)) {
            throw new \RuntimeException('Failed to persist backup archive to local target.');
        }

        return $destination;
    }
}
