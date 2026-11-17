<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Target;

use App\Module\Core\Application\Backup\RemoteArchiveProof;

/** Verifies a regular local file without loading a complete archive into memory. */
final class VerifiedArchiveFile
{
    /** @param callable():void $heartbeat */
    public static function inspect(string $path, callable $heartbeat): RemoteArchiveProof
    {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path)) {
            throw new \RuntimeException('Archive must be a regular file, not a symbolic link.');
        }
        $handle = fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Archive cannot be opened for verification.');
        }
        try {
            $before = fstat($handle);
            $hash = hash_init('sha256');
            $size = 0;
            while (!feof($handle)) {
                $heartbeat();
                $chunk = fread($handle, 1048576);
                if (false === $chunk) {
                    throw new \RuntimeException('Archive verification read failed.');
                }
                $size += strlen($chunk);
                hash_update($hash, $chunk);
            }
            $after = fstat($handle);
            clearstatcache(true, $path);
            $current = lstat($path);
            if (false === $before || false === $after || false === $current
                || array_intersect_key($before, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime', 'mode'])) !== array_intersect_key($after, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime', 'mode']))
                || $after['dev'] !== $current['dev'] || $after['ino'] !== $current['ino'] || $size !== $after['size']) {
                throw new \RuntimeException('Archive changed while being verified.');
            }
            $heartbeat();

            return new RemoteArchiveProof($size, hash_final($hash), $after['dev'].':'.$after['ino']);
        } finally {
            fclose($handle);
        }
    }
}
