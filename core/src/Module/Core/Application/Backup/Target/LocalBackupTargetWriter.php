<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Target;

use App\Module\Core\Application\Backup\BackupOperationHeartbeat;
use App\Module\Core\Application\Backup\BackupStorageTarget;

final class LocalBackupTargetWriter implements BackupTargetWriterInterface
{
    public function __construct(private readonly ?BackupOperationHeartbeat $lease = null)
    {
    }

    #[\Override]
    public function supports(BackupStorageTarget $target): bool
    {
        return 'local' === $target->type();
    }

    #[\Override]
    public function write(BackupStorageTarget $target, string $archiveName, string $sourceFile): string
    {
        $basePath = self::configText($target->config()['base_path'] ?? $target->config()['path'] ?? '');
        if ('' === $basePath || !str_starts_with($basePath, '/') || str_contains($basePath, "\0")) {
            throw new \InvalidArgumentException('Local backup target requires an absolute path config.');
        }

        $basePath = rtrim(str_replace('\\', '/', $basePath), '/');
        if ('' === $basePath) {
            throw new \InvalidArgumentException('Local backup target requires a dedicated directory.');
        }
        $archiveName = basename(str_replace('\\', '/', $archiveName));
        if ('' === $archiveName || '.' === $archiveName || '..' === $archiveName) {
            throw new \InvalidArgumentException('Invalid backup archive name.');
        }

        if (!is_file($sourceFile) || !is_readable($sourceFile)) {
            throw new \InvalidArgumentException('Backup source file is not readable.');
        }

        if (!is_dir($basePath) && !mkdir($basePath, 0775, true) && !is_dir($basePath)) {
            throw new \RuntimeException('Failed to create local backup destination directory.');
        }

        $realBase = realpath($basePath);
        if (false === $realBase) {
            throw new \RuntimeException('Failed to resolve local backup destination directory.');
        }

        $destination = $realBase.'/'.$archiveName;
        $destinationDir = dirname($destination);
        if ($destinationDir !== $realBase) {
            throw new \InvalidArgumentException('Local backup destination escaped the target directory.');
        }

        $temporary = @tempnam($realBase, '.easywi-backup-');
        if (false === $temporary || dirname($temporary) !== $realBase) {
            if (is_string($temporary)) {
                @unlink($temporary);
            }
            throw new \RuntimeException('Failed to create a private archive staging file in the target directory.');
        }
        try {
            if (!@chmod($temporary, 0600)) {
                throw new \RuntimeException('Failed to stage backup archive in the local target.');
            }
            $this->copyWithHeartbeat($sourceFile, $temporary);
            // A same-directory rename publishes the complete file atomically and replaces
            // a destination symlink itself instead of writing through it.
            $this->lease?->pulse(true);
            if (!@rename($temporary, $destination)) {
                throw new \RuntimeException('Failed to publish backup archive to local target.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return $destination;
    }

    private function copyWithHeartbeat(string $source, string $destination): void
    {
        $input = fopen($source, 'rb');
        $output = fopen($destination, 'wb');
        try {
            if (!is_resource($input) || !is_resource($output)) {
                throw new \RuntimeException('Cannot open archive staging streams.');
            }
            while (!feof($input)) {
                $this->lease?->pulse();
                $chunk = fread($input, 1048576);
                if (false === $chunk) {
                    throw new \RuntimeException('Archive staging read failed.');
                }
                $offset = 0;
                while ($offset < strlen($chunk)) {
                    $written = fwrite($output, substr($chunk, $offset));
                    if (false === $written || 0 === $written) {
                        throw new \RuntimeException('Archive staging write failed.');
                    }
                    $offset += $written;
                }
            }
            if (!fflush($output)) {
                throw new \RuntimeException('Archive staging flush failed.');
            }
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
        }
    }

    private static function configText(mixed $value): string
    {
        if (null === $value || is_scalar($value)) {
            return (string) $value;
        }

        throw new \InvalidArgumentException('Backup target field must be scalar.');
    }
}
