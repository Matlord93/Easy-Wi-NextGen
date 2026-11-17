<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

/** Hash large private staging files without letting the owning lease expire. */
final class BackupFileChecksum
{
    public static function sha256(string $path, ?BackupOperationHeartbeat $heartbeat = null): string
    {
        if (str_contains($path, "\0") || str_contains($path, '://')) {
            throw new \InvalidArgumentException('Backup checksum requires a local file.');
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open backup file for checksum verification.');
        }
        try {
            $hash = hash_init('sha256');
            while (!feof($stream)) {
                $heartbeat?->pulse();
                $chunk = fread($stream, 1048576);
                if ($chunk === false || ($chunk === '' && !feof($stream))) {
                    throw new \RuntimeException('Unable to read complete backup file for checksum verification.');
                }
                hash_update($hash, $chunk);
            }

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }
}
