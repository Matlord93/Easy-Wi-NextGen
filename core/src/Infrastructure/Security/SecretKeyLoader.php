<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

final class SecretKeyLoader
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

    public function loadKey(): string
    {
        if (!$this->isReadable()) {
            throw new \RuntimeException('Secret key file is not readable.');
        }

        $contents = file_get_contents($this->keyPath);
        if ($contents === false) {
            throw new \RuntimeException('Failed to read secret key file.');
        }

        $contents = trim($contents);
        if ($contents === '') {
            throw new \RuntimeException('Secret key file is empty.');
        }

        $decoded = base64_decode($contents, true);
        if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $decoded;
        }

        if (strlen($contents) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $contents;
        }

        throw new \RuntimeException('Secret key has invalid length.');
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
