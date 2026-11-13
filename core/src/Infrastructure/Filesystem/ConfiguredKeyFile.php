<?php

declare(strict_types=1);

namespace App\Infrastructure\Filesystem;

/** Reads an administrator-configured local key, never a PHP stream URL. */
final class ConfiguredKeyFile
{
    public static function read(string $path): string
    {
        $safe = ConfinedLocalPath::resolve(dirname($path), basename($path), true);
        if (null === $safe || !is_file($safe)) {
            throw new \RuntimeException('SFTP private key must be an existing absolute local file.');
        }
        $stream = @fopen($safe, 'rb');
        if (false === $stream) {
            throw new \RuntimeException('SFTP private key is not readable.');
        }
        try {
            $key = stream_get_contents($stream, 65537);
            if (false === $key || strlen($key) > 65536 || '' === trim($key)) {
                throw new \RuntimeException('SFTP private key is empty or exceeds 64 KiB.');
            }

            return $key;
        } finally {
            fclose($stream);
        }
    }
}
