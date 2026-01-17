<?php

declare(strict_types=1);

namespace App\Module\Setup\Runtime;

final class DatabaseConfig
{
    private const RELATIVE_PATH = '/srv/setup/config/database.json';

    public static function boot(string $projectDir): void
    {
        if (isset($_ENV['DATABASE_URL']) || isset($_SERVER['DATABASE_URL'])) {
            return;
        }

        $config = self::read($projectDir);
        if (!is_array($config)) {
            return;
        }

        $databaseUrl = $config['database_url'] ?? null;
        if (!is_string($databaseUrl) || $databaseUrl === '') {
            return;
        }

        $_ENV['DATABASE_URL'] = $databaseUrl;
        $_SERVER['DATABASE_URL'] = $databaseUrl;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function read(string $projectDir): ?array
    {
        $path = self::path($projectDir);
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function write(string $projectDir, array $payload): void
    {
        $path = self::path($projectDir);
        $directory = dirname($path);

        if (!is_dir($directory)) {
            if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException('Unable to create configuration directory.');
            }
        }

        $contents = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($contents === false) {
            throw new \RuntimeException('Unable to encode database configuration.');
        }

        $tempPath = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tempPath, $contents . "\n") === false) {
            throw new \RuntimeException('Unable to write database configuration.');
        }

        if (!@rename($tempPath, $path)) {
            @unlink($tempPath);
            throw new \RuntimeException('Unable to persist database configuration.');
        }
    }

    private static function path(string $projectDir): string
    {
        return rtrim($projectDir, '/') . self::RELATIVE_PATH;
    }
}
