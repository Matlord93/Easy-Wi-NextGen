<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use RuntimeException;

final class SecretsCrypto
{
    private const PREFIX = 'v1';

    private string $key;

    public function __construct(string $appSecret)
    {
        if ($appSecret === '') {
            throw new RuntimeException('APP_SECRET must be configured for SecretsCrypto.');
        }

        $this->key = sodium_crypto_generichash($appSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return sprintf(
            '%s:%s:%s',
            self::PREFIX,
            base64_encode($nonce),
            base64_encode($ciphertext),
        );
    }

    public function decrypt(string $payload): string
    {
        if ($payload === '') {
            return '';
        }

        $parts = explode(':', $payload, 3);
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            throw new RuntimeException('Unsupported secret payload format.');
        }

        $nonce = base64_decode($parts[1], true);
        $ciphertext = base64_decode($parts[2], true);

        if ($nonce === false || $ciphertext === false) {
            throw new RuntimeException('Invalid secret payload encoding.');
        }

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        if ($plaintext === false) {
            throw new RuntimeException('Unable to decrypt secret payload.');
        }

        return $plaintext;
    }
}
