<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application\Backup;

use App\Module\Core\Application\Backup\Adapter\BackupModuleAdapterInterface;
use App\Module\Core\Application\Backup\Adapter\BackupSnapshot;
use App\Module\Core\Application\Backup\Adapter\RestoreReport;

final class MusicbotBackupAdapter implements BackupModuleAdapterInterface
{
    public function module(): string
    {
        return 'musicbot';
    }

    public function snapshot(string $resourceId): BackupSnapshot
    {
        return new BackupSnapshot($resourceId, 'musicbot-'.$resourceId.'-backup.tar');
    }

    public function restore(string $resourceId, string $archivePath, bool $dryRun = false): RestoreReport
    {
        if (!file_exists($archivePath)) {
            return new RestoreReport($dryRun, false, 'Archive not found: '.$archivePath);
        }

        try {
            $this->validateArchive($archivePath);
        } catch (\InvalidArgumentException $e) {
            return new RestoreReport($dryRun, false, 'Archive validation failed: '.$e->getMessage());
        }

        return new RestoreReport($dryRun, true, 'Musicbot restore delegated to MusicbotRestoreService.');
    }

    private function validateArchive(string $archivePath): void
    {
        $realPath = realpath($archivePath);
        if ($realPath === false) {
            throw new \InvalidArgumentException('Cannot resolve archive path.');
        }

        if (str_ends_with($archivePath, '.tar')) {
            $this->validateTarEntries($archivePath);

            return;
        }

        try {
            $archive = new \PharData($archivePath);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Cannot open archive: '.$e->getMessage());
        }

        foreach (new \RecursiveIteratorIterator($archive) as $file) {
            $entryPath = $file->getPathname();

            if (str_contains($entryPath, '..')) {
                throw new \InvalidArgumentException('Archive contains path traversal entry: '.$entryPath);
            }

            if ($file instanceof \PharFileInfo && $file->isLink()) {
                throw new \InvalidArgumentException('Archive contains symlink entry: '.$entryPath);
            }
        }
    }

    private function validateTarEntries(string $archivePath): void
    {
        $fh = fopen($archivePath, 'rb');
        if (!is_resource($fh)) {
            throw new \InvalidArgumentException('Cannot open tar archive for validation.');
        }

        try {
            while (!feof($fh)) {
                $header = fread($fh, 512);
                if ($header === false || strlen($header) < 512 || $header === str_repeat("\0", 512)) {
                    break;
                }

                $name = rtrim(substr($header, 0, 100), "\0");
                $linkTarget = rtrim(substr($header, 157, 100), "\0");
                $typeFlag = substr($header, 156, 1);

                if ($name !== '' && (str_contains($name, '../') || str_starts_with($name, '/') || $name === '..')) {
                    throw new \InvalidArgumentException('Archive contains path traversal entry: '.$name);
                }

                if ($typeFlag === '2' || ($linkTarget !== '' && $typeFlag !== '')) {
                    throw new \InvalidArgumentException('Archive contains symlink entry: '.$name);
                }

                $sizeOctal = rtrim(substr($header, 124, 12), "\0 ");
                $size = $sizeOctal !== '' ? octdec($sizeOctal) : 0;

                if (is_int($size) && $size > 0) {
                    $blocks = (int) ceil($size / 512);
                    fseek($fh, $blocks * 512, SEEK_CUR);
                }
            }
        } finally {
            fclose($fh);
        }
    }
}
