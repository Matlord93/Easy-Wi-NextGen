<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application\Backup;

use App\Module\Core\Application\Backup\BackupOperationHeartbeat;

/** Bounded-memory media reads, keeping the owning worker's lease alive. */
final class MusicbotArchiveStreams
{
    public function __construct(private readonly ?BackupOperationHeartbeat $heartbeat = null)
    {
    }

    public function checksum(string $path): string
    {
        $input = fopen($path, 'rb');
        if ($input === false) {
            throw new \RuntimeException('Cannot read Musicbot archive stream.');
        }
        try {
            $context = hash_init('sha256');
            while (!feof($input)) {
                $this->heartbeat?->pulse();
                $chunk = fread($input, 1_048_576);
                if ($chunk === false || ($chunk === '' && !feof($input))) {
                    throw new \RuntimeException('Cannot checksum complete Musicbot archive stream.');
                }
                hash_update($context, $chunk);
            }
            $this->heartbeat?->pulse(true);

            return hash_final($context);
        } finally {
            fclose($input);
        }
    }

}
