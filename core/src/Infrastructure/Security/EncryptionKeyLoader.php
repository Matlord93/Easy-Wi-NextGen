<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

final class EncryptionKeyLoader
{
    private const DEFAULT_KEY_PATH = '/etc/easywi/secret.key';
    private const FALLBACK_KEY_DIR = 'var/easywi';

    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(default::EASYWI_SECRET_KEY_PATH)%')]
        ?string $envPath = null,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%kernel.project_dir%')]
        ?string $projectDir = null,
    ) {
        $this->keyPath = $this->resolveKeyPath($envPath, $projectDir);
    }

    private string $keyPath;

    public function getKeyPath(): string
    {
        return $this->keyPath;
    }

    public function isReadable(): bool
    {
        return is_readable($this->keyPath);
    }

    /**
     * @return array{active_key_id: string, keyring: string}
     */
    public function loadKeyring(): array
    {

        $envKeyring = trim((string) ($_ENV['APP_ENCRYPTION_KEYS'] ?? $_SERVER['APP_ENCRYPTION_KEYS'] ?? ''));
        if ($envKeyring !== '') {
            $activeFromEnv = trim((string) ($_ENV['ACTIVE_ENCRYPTION_KEY_ID'] ?? $_SERVER['ACTIVE_ENCRYPTION_KEY_ID'] ?? $_ENV['APP_ENCRYPTION_ACTIVE_KEY_ID'] ?? $_SERVER['APP_ENCRYPTION_ACTIVE_KEY_ID'] ?? ''));
            if ($activeFromEnv === '') {
                $activeFromEnv = trim((string) strtok($envKeyring, ':'));
            }

            return ['active_key_id' => $activeFromEnv, 'keyring' => $envKeyring];
        }

        if (!$this->isReadable()) {
            return ['active_key_id' => '', 'keyring' => ''];
        }

        $contents = file_get_contents($this->keyPath);
        if ($contents === false) {
            return ['active_key_id' => '', 'keyring' => ''];
        }

        $contents = trim($contents);
        if ($contents === '') {
            return ['active_key_id' => '', 'keyring' => ''];
        }

        $decoded = json_decode($contents, true);
        if (is_array($decoded)) {
            $keyring = '';
            if (isset($decoded['keyring']) && is_string($decoded['keyring'])) {
                $keyring = trim($decoded['keyring']);
            } elseif (isset($decoded['keys']) && is_array($decoded['keys'])) {
                $entries = [];
                foreach ($decoded['keys'] as $keyId => $value) {
                    if (!is_string($keyId) || !is_string($value)) {
                        continue;
                    }
                    $keyId = trim($keyId);
                    $value = trim($value);
                    if ($keyId !== '' && $value !== '') {
                        $entries[] = sprintf('%s:%s', $keyId, $value);
                    }
                }
                $keyring = implode(',', $entries);
            }

            $activeKeyId = '';
            if (isset($decoded['active_key_id']) && is_string($decoded['active_key_id'])) {
                $activeKeyId = trim($decoded['active_key_id']);
            }
            if ($activeKeyId === '' && $keyring !== '') {
                $activeKeyId = trim((string) strtok($keyring, ':'));
            }

            return ['active_key_id' => $activeKeyId, 'keyring' => $keyring];
        }

        if (str_contains($contents, ':')) {
            $activeKeyId = trim((string) strtok($contents, ':'));

            return ['active_key_id' => $activeKeyId, 'keyring' => $contents];
        }

        return ['active_key_id' => 'v1', 'keyring' => 'v1:' . $contents];
    }

    private function resolveKeyPath(?string $envPath, ?string $projectDir): string
    {
        $envPath = $envPath === null ? '' : trim($envPath);
        if ($envPath !== '') {
            return $envPath;
        }

        $candidates = [
            self::DEFAULT_KEY_PATH,
            ...$this->resolveFallbackKeyPaths($projectDir),
        ];

        foreach ($candidates as $candidate) {
            if (is_readable($candidate)) {
                return $candidate;
            }
        }

        return $candidates[0] ?? self::DEFAULT_KEY_PATH;
    }

    /**
     * @return list<string>
     */
    private function resolveFallbackKeyPaths(?string $projectDir): array
    {
        if ($projectDir === null || trim($projectDir) === '') {
            return [];
        }

        $normalized = rtrim($projectDir, '/');
        $paths = [
            $normalized . '/' . self::FALLBACK_KEY_DIR . '/secret.key',
        ];

        $parentDir = dirname($normalized);
        if ($parentDir !== '' && $parentDir !== $normalized) {
            $paths[] = $parentDir . '/' . self::FALLBACK_KEY_DIR . '/secret.key';
        }

        return array_values(array_unique($paths));
    }
}
