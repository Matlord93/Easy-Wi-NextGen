<?php

declare(strict_types=1);

namespace App\Module\Setup\Application;

final class InstallEnvBootstrap
{
    private const TARGET_KEYS = ['APP_SECRET', 'APP_ENCRYPTION_KEYS', 'APP_ENCRYPTION_ACTIVE_KEY_ID'];

    public function __construct(
        private readonly KeyMaterialGenerator $keyGenerator = new KeyMaterialGenerator(),
        private readonly EnvFileWriter $fileWriter = new EnvFileWriter(),
    ) {
    }

    /**
     * @return array{ok: bool, error_code?: string, env_path: string, written_keys?: list<string>}
     */
    public function ensure(string $projectDir): array
    {
        $check = $this->checkStatus($projectDir);
        if (!($check['writable'] ?? false)) {
            return [
                'ok' => false,
                'error_code' => 'env_file_not_writable',
                'env_path' => (string) $check['env_path'],
            ];
        }

        $missing = $this->resolveMissingValues($projectDir);
        $envPath = (string) $check['env_path'];

        if ($missing === []) {
            return ['ok' => true, 'env_path' => $envPath, 'written_keys' => []];
        }

        try {
            $written = $this->fileWriter->setMissingKeys($envPath, $missing, [
                rtrim($projectDir, '/') . '/.env',
                $envPath,
            ]);
        } catch (\RuntimeException $exception) {
            return [
                'ok' => false,
                'error_code' => $exception->getMessage(),
                'env_path' => $envPath,
            ];
        }

        return ['ok' => true, 'env_path' => $envPath, 'written_keys' => $written];
    }

    /**
     * @return array{env_path: string, missing_keys: list<string>, writable: bool}
     */
    public function checkStatus(string $projectDir): array
    {
        $envPath = rtrim($projectDir, '/') . '/.env.local';

        return [
            'env_path' => $envPath,
            'missing_keys' => array_keys($this->resolveMissingValues($projectDir)),
            'writable' => $this->isWritable($envPath),
        ];
    }

    /**
     * @return array{env_path: string, missing_keys: list<string>}
     */
    public function checkMissing(string $projectDir): array
    {
        $status = $this->checkStatus($projectDir);

        return [
            'env_path' => $status['env_path'],
            'missing_keys' => $status['missing_keys'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function resolveMissingValues(string $projectDir): array
    {
        $existing = $this->loadEnvironmentMap($projectDir);
        $missing = [];

        if (!array_key_exists('APP_SECRET', $existing)) {
            $missing['APP_SECRET'] = $this->keyGenerator->generateAppSecret();
        }

        if (!array_key_exists('APP_ENCRYPTION_KEYS', $existing)) {
            $keyset = $this->keyGenerator->generateEncryptionKeyset();
            $missing['APP_ENCRYPTION_KEYS'] = $this->keyGenerator->buildCsvKeyset($keyset['keys']);

            if (!array_key_exists('APP_ENCRYPTION_ACTIVE_KEY_ID', $existing)) {
                $missing['APP_ENCRYPTION_ACTIVE_KEY_ID'] = $keyset['activeKid'];
            }
        } elseif (!array_key_exists('APP_ENCRYPTION_ACTIVE_KEY_ID', $existing)) {
            $firstKey = $this->extractFirstKeyId((string) $existing['APP_ENCRYPTION_KEYS']);
            if ($firstKey !== '') {
                $missing['APP_ENCRYPTION_ACTIVE_KEY_ID'] = $firstKey;
            }
        }

        return $missing;
    }

    /**
     * @return array<string, string>
     */
    private function loadEnvironmentMap(string $projectDir): array
    {
        $values = [];

        foreach (self::TARGET_KEYS as $key) {
            $v = $_ENV[$key] ?? $_SERVER[$key] ?? null;
            if (is_string($v)) {
                $values[$key] = $v;
            }
        }

        foreach (['.env', '.env.local'] as $file) {
            $path = rtrim($projectDir, '/') . '/' . $file;
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }

            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines)) {
                continue;
            }

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$rawKey, $rawValue] = array_map('trim', explode('=', $line, 2));
                if (!in_array($rawKey, self::TARGET_KEYS, true)) {
                    continue;
                }

                $values[$rawKey] = $this->stripQuotes($rawValue);
            }
        }

        return $values;
    }

    private function stripQuotes(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    private function extractFirstKeyId(string $keyset): string
    {
        $entry = trim((string) explode(',', $keyset)[0]);
        if ($entry === '' || !str_contains($entry, ':')) {
            return '';
        }

        return trim((string) explode(':', $entry, 2)[0]);
    }

    private function isWritable(string $envPath): bool
    {
        if (is_file($envPath)) {
            return is_writable($envPath);
        }

        return is_writable(dirname($envPath));
    }
}
