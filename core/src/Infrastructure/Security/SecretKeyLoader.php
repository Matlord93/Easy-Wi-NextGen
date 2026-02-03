<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

final class SecretKeyLoader
{
    private const DEFAULT_KEY_PATH = '/etc/easywi/secret.key';

    public function __construct(
        private readonly string $keyPath = self::DEFAULT_KEY_PATH,
    ) {
    }

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
}
