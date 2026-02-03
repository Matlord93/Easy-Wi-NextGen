<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

final class CryptoService
{
    public function __construct(
        private readonly SecretKeyLoader $keyLoader,
    ) {
    }

    /**
     * @return array{nonce: string, ciphertext: string}
     */
    public function encrypt(string $plaintext): array
    {
        $key = $this->keyLoader->loadKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);

        return [
            'nonce' => base64_encode($nonce),
            'ciphertext' => base64_encode($ciphertext),
        ];
    }

    /**
     * @param array{nonce?: string, ciphertext?: string} $payload
     */
    public function decrypt(array $payload): string
    {
        $nonce = $this->decodeBase64Field($payload['nonce'] ?? null, 'nonce');
        $ciphertext = $this->decodeBase64Field($payload['ciphertext'] ?? null, 'ciphertext');
        $key = $this->keyLoader->loadKey();

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        if ($plaintext === false) {
            throw new \RuntimeException('Unable to decrypt payload.');
        }

        return $plaintext;
    }

    private function decodeBase64Field(?string $value, string $field): string
    {
        if ($value === null || $value === '') {
            throw new \RuntimeException(sprintf('Missing encryption %s.', $field));
        }

        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new \RuntimeException(sprintf('Invalid base64 encoding for %s.', $field));
        }

        return $decoded;
    }
}
