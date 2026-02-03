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

        if (!is_readable(self::DEFAULT_KEY_PATH) && $projectDir !== null) {
            return rtrim($projectDir, '/') . '/' . self::FALLBACK_KEY_DIR . '/secret.key';
        }

        return self::DEFAULT_KEY_PATH;
    }
}
