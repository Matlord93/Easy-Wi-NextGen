<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use RuntimeException;

final class EncryptionService
{
    private const KEY_BYTES = SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES;
    private const NONCE_BYTES = SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES;

    /**
     * @var array<string, string>
     */
    private array $keyring;

    private readonly string $activeKeyId;

    public function __construct(
        ?string $activeKeyId,
        ?string $keyring,
    ) {
        $this->activeKeyId = $activeKeyId ?? '';
        $this->keyring = $this->parseKeyring($keyring ?? '');
    }

    /**
     * @return array{key_id: string, nonce: string, ciphertext: string}
     */
    public function encrypt(string $plaintext): array
    {
        $keyId = $this->activeKeyId;
        if ($keyId === '') {
            throw new RuntimeException('No active encryption key configured.');
        }
        $key = $this->requireKey($keyId);
        $nonce = random_bytes(self::NONCE_BYTES);
        $ciphertext = sodium_crypto_aead_aes256gcm_encrypt(
            $plaintext,
            $this->aadForKey($keyId),
            $nonce,
            $key,
        );

        return [
            'key_id' => $keyId,
            'nonce' => base64_encode($nonce),
            'ciphertext' => base64_encode($ciphertext),
        ];
    }

    /**
     * @param array{key_id?: string, nonce?: string, ciphertext?: string} $payload
     */
    public function decrypt(array $payload): string
    {
        $keyId = (string) ($payload['key_id'] ?? '');
        if ($keyId === '') {
            throw new RuntimeException('Missing encryption key id.');
        }

        $nonce = $this->decodeBase64Field($payload['nonce'] ?? null, 'nonce');
        $ciphertext = $this->decodeBase64Field($payload['ciphertext'] ?? null, 'ciphertext');
        $key = $this->requireKey($keyId);

        $plaintext = sodium_crypto_aead_aes256gcm_decrypt(
            $ciphertext,
            $this->aadForKey($keyId),
            $nonce,
            $key,
        );

        if ($plaintext === false) {
            throw new RuntimeException('Unable to decrypt payload.');
        }

        return $plaintext;
    }

    private function aadForKey(string $keyId): string
    {
        return 'key:' . $keyId;
    }

    private function decodeBase64Field(?string $value, string $field): string
    {
        if ($value === null || $value === '') {
            throw new RuntimeException(sprintf('Missing encryption %s.', $field));
        }

        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new RuntimeException(sprintf('Invalid base64 encoding for %s.', $field));
        }

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    private function parseKeyring(string $keyring): array
    {
        if ($keyring === '') {
            return [];
        }

        $entries = array_filter(array_map('trim', explode(',', $keyring)));
        $parsed = [];

        foreach ($entries as $entry) {
            [$keyId, $encodedKey] = array_pad(explode(':', $entry, 2), 2, '');
            $keyId = trim($keyId);
            $encodedKey = trim($encodedKey);

            if ($keyId === '' || $encodedKey === '') {
                throw new RuntimeException('Invalid encryption key format.');
            }

            $decodedKey = base64_decode($encodedKey, true);
            if ($decodedKey === false || strlen($decodedKey) !== self::KEY_BYTES) {
                throw new RuntimeException(sprintf('Invalid key material for key id "%s".', $keyId));
            }

            $parsed[$keyId] = $decodedKey;
        }

        return $parsed;
    }

    private function requireKey(string $keyId): string
    {
        $key = $this->keyring[$keyId] ?? null;
        if ($key === null) {
            throw new RuntimeException(sprintf('Encryption key "%s" is not available.', $keyId));
        }

        return $key;
    }
}
