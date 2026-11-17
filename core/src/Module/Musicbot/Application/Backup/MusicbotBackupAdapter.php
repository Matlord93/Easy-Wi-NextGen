<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application\Backup;

use App\Module\Core\Application\Backup\Adapter\BackupModuleAdapterInterface;
use App\Module\Core\Application\Backup\Adapter\BackupSnapshot;
use App\Module\Core\Application\Backup\Adapter\RestoreReport;

final class MusicbotBackupAdapter implements BackupModuleAdapterInterface
{
    private const MAX_MANIFEST_BYTES = 67_108_864;

    public function __construct(private readonly ?MusicbotManifestOperationsInterface $operations = null)
    {
    }

    #[\Override]
    public function module(): string
    {
        return 'musicbot';
    }

    #[\Override]
    public function snapshot(string $resourceId): BackupSnapshot
    {
        if ($this->operations === null) {
            throw new \LogicException('Musicbot backup operations are not configured.');
        }
        $json = $this->operations->exportManifest($resourceId);
        if (strlen($json) > self::MAX_MANIFEST_BYTES) {
            throw new \InvalidArgumentException('Musicbot configuration manifest exceeds the supported size.');
        }
        $path = sys_get_temp_dir().'/easywi-backup-snapshot-'.bin2hex(random_bytes(12));
        if (!mkdir($path, 0700)) {
            throw new \RuntimeException('Cannot create Musicbot backup staging directory.');
        }
        try {
            if (file_put_contents($path.'/backup.json', $json) !== strlen($json)) {
                throw new \RuntimeException('Cannot write Musicbot backup manifest.');
            }
            chmod($path.'/backup.json', 0600);

            return BackupSnapshot::temporaryDirectory($path, 'musicbot-backup.tar');
        } catch (\Throwable $e) {
            if (is_file($path.'/backup.json')) {
                unlink($path.'/backup.json');
            }
            rmdir($path);
            throw $e;
        }
    }

    #[\Override]
    public function restore(string $resourceId, string $archivePath, bool $dryRun = false): RestoreReport
    {
        if (str_contains($archivePath, "\0") || str_contains($archivePath, '://') || !is_file($archivePath)) {
            return new RestoreReport($dryRun, false, 'Archive not found or not a local file.');
        }
        $scratchDirectory = null;
        try {
            if (str_ends_with(strtolower($archivePath), '.json')) {
                $size = filesize($archivePath);
                if ($size === false || $size > self::MAX_MANIFEST_BYTES) {
                    throw new \InvalidArgumentException('Musicbot manifest exceeds the supported size.');
                }
                $json = file_get_contents($archivePath, false, null, 0, self::MAX_MANIFEST_BYTES + 1);
                if ($json === false || strlen($json) > self::MAX_MANIFEST_BYTES) {
                    throw new \InvalidArgumentException('Cannot read Musicbot manifest.');
                }
            } else {
                if (str_ends_with(strtolower($archivePath), '.gz')) {
                    $scratchDirectory = sys_get_temp_dir().'/easywi-musicbot-restore-'.bin2hex(random_bytes(12));
                    if (!mkdir($scratchDirectory, 0700)) {
                        throw new \RuntimeException('Cannot create restore staging directory.');
                    }
                    $archivePath = $this->decompressGzip($archivePath, $scratchDirectory.'/archive.tar');
                }
                $this->validateArchive($archivePath);
                $archive = new \PharData($archivePath);
                if (!isset($archive['backup.json'])) {
                    throw new \InvalidArgumentException('Musicbot archive does not contain backup.json.');
                }
                $entry = self::manifestEntry($archive['backup.json']);
                if ($entry->getSize() > self::MAX_MANIFEST_BYTES) {
                    throw new \InvalidArgumentException('Musicbot manifest exceeds the supported size.');
                }
                $json = $entry->getContent();
            }
            if ($this->operations === null) {
                return new RestoreReport($dryRun, false, 'Musicbot restore operations are not configured. No restore was performed.');
            }

            return $this->operations->restoreManifest($resourceId, $json, $dryRun);
        } catch (\Throwable $e) {
            return new RestoreReport($dryRun, false, 'Musicbot backup validation or restore failed: '.$e->getMessage());
        } finally {
            if ($scratchDirectory !== null) {
                if (is_file($scratchDirectory.'/archive.tar')) {
                    unlink($scratchDirectory.'/archive.tar');
                }
                rmdir($scratchDirectory);
            }
        }
    }

    private function decompressGzip(string $source, string $target): string
    {
        $input = gzopen($source, 'rb');
        $output = fopen($target, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                gzclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new \InvalidArgumentException('Cannot open compressed archive.');
        }
        try {
            $bytes = 0;
            while (!gzeof($input)) {
                $chunk = self::readGzipChunk($input);
                if ($chunk === false || ($chunk === '' && !gzeof($input))) {
                    throw new \InvalidArgumentException('Invalid compressed archive.');
                }
                $bytes += strlen($chunk);
                if ($bytes > 536_870_912) {
                    throw new \InvalidArgumentException('Expanded Musicbot archive exceeds the supported size.');
                }
                if (fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('Cannot write expanded archive.');
                }
            }
        } finally {
            gzclose($input);
            fclose($output);
        }

        return $target;
    }

    /** @param resource $input */
    private static function readGzipChunk($input): string|false
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException('Invalid compressed archive: '.$message, 0, $severity);
        }, E_WARNING);
        try {
            return gzread($input, 65_536);
        } finally {
            restore_error_handler();
        }
    }

    private static function manifestEntry(mixed $entry): \PharFileInfo
    {
        if (!$entry instanceof \PharFileInfo || !$entry->isFile() || $entry->isLink()) {
            throw new \InvalidArgumentException('Musicbot manifest must be a regular file.');
        }

        return $entry;
    }

    private function validateArchive(string $archivePath): void
    {
        $realPath = realpath($archivePath);
        if ($realPath === false) {
            throw new \InvalidArgumentException('Cannot resolve archive path.');
        }

        $this->validateTarEntries($realPath);
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
                $prefix = rtrim(substr($header, 345, 155), "\0");
                if ($prefix !== '') {
                    $name = $prefix.'/'.$name;
                }
                $name = str_replace('\\', '/', $name);
                $linkTarget = rtrim(substr($header, 157, 100), "\0");
                $typeFlag = substr($header, 156, 1);

                if ($name !== '' && (str_contains($name, "\0") || in_array('..', explode('/', $name), true) || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) === 1)) {
                    throw new \InvalidArgumentException('Archive contains path traversal entry: '.$name);
                }

                if (!in_array($typeFlag, ["\0", '0', '5'], true) || $linkTarget !== '') {
                    throw new \InvalidArgumentException('Archive contains symlink entry: '.$name);
                }

                $sizeOctal = rtrim(substr($header, 124, 12), "\0 ");
                if ($sizeOctal !== '' && (strlen($sizeOctal) > 11 || preg_match('/^[0-7]+$/D', $sizeOctal) !== 1)) {
                    throw new \InvalidArgumentException('Archive contains invalid size metadata.');
                }
                $size = $sizeOctal !== '' ? (int) octdec($sizeOctal) : 0;
                $position = ftell($fh);
                $archiveBytes = filesize($archivePath);
                if ($position === false || $archiveBytes === false || $size > $archiveBytes - $position) {
                    throw new \InvalidArgumentException('Archive entry is truncated.');
                }

                if ($size > 0) {
                    $blocks = (int) ceil($size / 512);
                    if (fseek($fh, $blocks * 512, SEEK_CUR) !== 0) {
                        throw new \InvalidArgumentException('Cannot read archive entry.');
                    }
                }
            }
        } finally {
            fclose($fh);
        }
    }
}
