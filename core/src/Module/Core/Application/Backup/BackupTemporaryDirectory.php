<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

final class BackupTemporaryDirectory
{
    public static function create(string $prefix = 'easywi-backup-work-'): string
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,63}-$/D', $prefix) !== 1) {
            throw new \InvalidArgumentException('Invalid backup staging prefix.');
        }
        $parent = realpath(sys_get_temp_dir());
        if ($parent === false) {
            throw new \RuntimeException('Unable to resolve backup staging parent.');
        }
        $path = $parent.'/'.$prefix.bin2hex(random_bytes(12));
        if (!mkdir($path, 0700)) {
            throw new \RuntimeException('Unable to create private backup staging directory.');
        }

        if (!chmod($path, 0700)) {
            @rmdir($path);
            throw new \RuntimeException('Unable to restrict backup staging directory permissions.');
        }

        return $path;
    }

    public static function isOwned(string $path, string $prefix): bool
    {
        $parent = realpath(sys_get_temp_dir());
        $real = realpath($path);

        return $parent !== false && $real !== false && !is_link($path)
            && $real === $path && dirname($real) === $parent
            && preg_match('/^'.preg_quote($prefix, '/').'[a-f0-9]{24}$/D', basename($real)) === 1;
    }

    /** Best-effort cleanup: never masks the original backup/restore exception. */
    public static function remove(string $path, string $prefix): void
    {
        if (!self::isOwned($path, $prefix)) {
            return;
        }
        self::removeContents($path);
        @rmdir($path);
    }

    private static function removeContents(string $path): void
    {
        $entries = @scandir($path);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $entry = $path.'/'.$name;
            if (is_link($entry) || !is_dir($entry)) {
                @unlink($entry);
            } else {
                self::removeContents($entry);
                @rmdir($entry);
            }
        }
    }
}
