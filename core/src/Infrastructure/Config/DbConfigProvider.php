<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

use App\Infrastructure\Security\CryptoService;
use App\Infrastructure\Security\SecretKeyLoader;

final class DbConfigProvider
{
    private const DEFAULT_DB_CONFIG_PATH = 'var/easywi/db.json';
    private const FALLBACK_DB_CONFIG_DIR = 'var/easywi';

    private string $configPath;

    public function __construct(
        private readonly CryptoService $crypto,
        private readonly SecretKeyLoader $keyLoader,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(default::EASYWI_DB_CONFIG_PATH)%')]
        ?string $envPath = null,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%kernel.project_dir%')]
        ?string $projectDir = null,
    ) {
        $this->configPath = $this->resolveConfigPath($envPath, $projectDir);
    }

    public function getConfigPath(): string
    {
        return $this->configPath;
    }

    public function exists(): bool
    {
        return is_file($this->configPath);
    }

    public function isKeyReadable(): bool
    {
        return $this->keyLoader->isReadable();
    }

    public function getKeyPath(): string
    {
        return $this->keyLoader->getKeyPath();
    }

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        if (!$this->exists()) {
            throw new \RuntimeException('Database configuration file not found.');
        }

        $contents = file_get_contents($this->configPath);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read database configuration file.');
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Database configuration file contains invalid JSON.');
        }

        $plaintext = $this->crypto->decrypt($decoded);
        $payload = json_decode($plaintext, true);
        if (!is_array($payload)) {
            throw new \RuntimeException('Database configuration payload is invalid.');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    public function validate(array $payload): array
    {
        $errors = [];

        $required = ['host', 'dbname', 'user'];
        foreach ($required as $field) {
            $value = $payload[$field] ?? null;
            if (!is_string($value) || trim($value) === '') {
                $errors[] = sprintf('missing_%s', $field);
            }
        }

        if (array_key_exists('port', $payload) && $payload['port'] !== null) {
            $port = $payload['port'];
            if (!is_int($port) && (!is_string($port) || !ctype_digit($port))) {
                $errors[] = 'invalid_port';
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function store(array $payload): void
    {
        $directory = dirname($this->configPath);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create database config directory.');
        }

        $encrypted = $this->crypto->encrypt($this->encodePayload($payload));
        $contents = json_encode($encrypted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($contents === false) {
            throw new \RuntimeException('Unable to encode database configuration.');
        }

        $tempPath = $this->configPath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tempPath, $contents . "\n") === false) {
            throw new \RuntimeException('Unable to write database configuration file.');
        }

        if (!@rename($tempPath, $this->configPath)) {
            @unlink($tempPath);
            throw new \RuntimeException('Unable to persist database configuration file.');
        }

        @chmod($this->configPath, 0640);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function toConnectionParams(array $payload): array
    {
        $port = $payload['port'] ?? null;
        if (is_string($port) && ctype_digit($port)) {
            $port = (int) $port;
        }

        return array_filter([
            'driver' => 'pdo_mysql',
            'host' => (string) ($payload['host'] ?? ''),
            'port' => is_int($port) ? $port : null,
            'dbname' => (string) ($payload['dbname'] ?? ''),
            'user' => (string) ($payload['user'] ?? ''),
            'password' => (string) ($payload['password'] ?? ''),
            'serverVersion' => isset($payload['serverVersion']) ? (string) $payload['serverVersion'] : null,
            'charset' => (string) ($payload['charset'] ?? 'utf8mb4'),
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodePayload(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Unable to encode database configuration payload.');
        }

        return $encoded;
    }

    private function resolveConfigPath(?string $envPath, ?string $projectDir): string
    {
        $envPath = $envPath === null ? '' : trim($envPath);
        if ($envPath !== '') {
            return $envPath;
        }

        $projectDefaultPath = $projectDir !== null
            ? rtrim($projectDir, '/') . '/' . self::DEFAULT_DB_CONFIG_PATH
            : self::DEFAULT_DB_CONFIG_PATH;

        if (!$this->isPathWritable($projectDefaultPath) && $projectDir !== null) {
            return rtrim($projectDir, '/') . '/' . self::FALLBACK_DB_CONFIG_DIR . '/db.json';
        }

        return $projectDefaultPath;
    }

    private function isPathWritable(string $path): bool
    {
        if (is_file($path)) {
            return is_writable($path);
        }

        $directory = dirname($path);
        if (is_dir($directory)) {
            return is_writable($directory);
        }

        $parent = dirname($directory);

        return is_dir($parent) && is_writable($parent);
    }
}
