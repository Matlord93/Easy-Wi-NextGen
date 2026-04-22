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
     * @return array{nonce: string, ciphertext: string, backend?: string, tag?: string}
     */
    public function encrypt(string $plaintext): array
    {
        $key = $this->keyLoader->loadKey();

        if ($this->isSecretboxAvailable()) {
            $nonceBytes = \defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES') ? \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES : 24;
            $nonce = random_bytes($nonceBytes);
            $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);

            return [
                'nonce' => base64_encode($nonce),
                'ciphertext' => base64_encode($ciphertext),
            ];
        }

        return $this->encryptWithOpenSsl($plaintext, $key);
    }

    /**
     * @param array{nonce?: string, ciphertext?: string, backend?: string, tag?: string} $payload
     */
    public function decrypt(array $payload): string
    {
        $nonce = $this->decodeBase64Field($payload['nonce'] ?? null, 'nonce');
        $ciphertext = $this->decodeBase64Field($payload['ciphertext'] ?? null, 'ciphertext');
        $key = $this->keyLoader->loadKey();

        $backend = (string) ($payload['backend'] ?? '');
        if ($backend === 'openssl' || array_key_exists('tag', $payload)) {
            return $this->decryptWithOpenSsl($payload, $nonce, $ciphertext, $key);
        }

        if (!$this->isSecretboxAvailable()) {
            throw new \RuntimeException('Libsodium extension is required to decrypt existing database configuration payloads.');
        }

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

    private function encryptWithOpenSsl(string $plaintext, string $key): array
    {
        if (!\function_exists('openssl_encrypt')) {
            throw new \RuntimeException('No supported encryption backend is available (libsodium/openssl).');
        }

        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ciphertext === false) {
            throw new \RuntimeException('Unable to encrypt payload.');
        }

        return [
            'backend' => 'openssl',
            'nonce' => base64_encode($nonce),
            'ciphertext' => base64_encode($ciphertext),
            'tag' => base64_encode($tag),
        ];
    }

    /**
     * @param array{tag?: string} $payload
     */
    private function decryptWithOpenSsl(array $payload, string $nonce, string $ciphertext, string $key): string
    {
        if (!\function_exists('openssl_decrypt')) {
            throw new \RuntimeException('OpenSSL extension is required to decrypt this payload.');
        }

        $tag = $this->decodeBase64Field($payload['tag'] ?? null, 'tag');
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '');
        if ($plaintext === false) {
            throw new \RuntimeException('Unable to decrypt payload.');
        }

        return $plaintext;
    }

    private function isSecretboxAvailable(): bool
    {
        return \function_exists('sodium_crypto_secretbox') && \function_exists('sodium_crypto_secretbox_open');
    }
}

