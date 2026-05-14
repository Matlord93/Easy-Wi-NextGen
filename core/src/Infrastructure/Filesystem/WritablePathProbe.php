<?php

declare(strict_types=1);

namespace App\Infrastructure\Filesystem;

final class WritablePathProbe
{
    public static function directory(string $path): bool
    {
        clearstatcache(true, $path);

        if (!is_dir($path)) {
            return false;
        }

        if (self::canCreateTemporaryFile($path)) {
            return true;
        }

        return is_writable($path);
    }

    public static function directoryOrCreatable(string $path): bool
    {
        clearstatcache(true, $path);

        if (is_dir($path)) {
            return self::directory($path);
        }

        $parent = dirname($path);
        if ($parent === $path || !is_dir($parent)) {
            return false;
        }

        return self::directory($parent);
    }

    public static function fileTarget(string $path): bool
    {
        clearstatcache(true, $path);

        if (is_file($path)) {
            return is_writable($path) || self::directory(dirname($path));
        }

        return self::directoryOrCreatable(dirname($path));
    }

    private static function canCreateTemporaryFile(string $directory): bool
    {
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);
        if ($directory === '') {
            $directory = DIRECTORY_SEPARATOR;
        }

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            try {
                $suffix = bin2hex(random_bytes(8));
            } catch (\Random\RandomException) {
                $suffix = uniqid('', true);
            }

            $probePath = $directory . DIRECTORY_SEPARATOR . '.easywi-write-test-' . $suffix;
            $handle = @fopen($probePath, 'x');
            if ($handle === false) {
                continue;
            }

            $written = @fwrite($handle, '1') === 1;
            @fclose($handle);
            @unlink($probePath);

            return $written;
        }

        return false;
    }
}
