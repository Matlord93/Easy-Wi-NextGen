<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use RuntimeException;

class SecretsCrypto
{
    private const PREFIX = 'v1';
    private const OPENSSL_CIPHER = 'aes-256-gcm';

    /**
     * @var string[]
     */
    private array $keys;

    public function __construct(string $appSecret, ?string $fallbackSecrets = null)
    {
        if ($appSecret === '') {
            throw new RuntimeException('APP_SECRET must be configured for SecretsCrypto.');
        }

        $secrets = array_filter(array_map('trim', array_merge([$appSecret], explode(',', $fallbackSecrets ?? ''))));
        $this->keys = array_map(
            fn (string $secret): string => $this->deriveKey($secret),
            $secrets,
        );
    }

    private function deriveKey(string $secret): string
    {
        if (function_exists('sodium_crypto_generichash') && defined('SODIUM_CRYPTO_SECRETBOX_KEYBYTES')) {
            return \sodium_crypto_generichash($secret, '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        }

        return substr(hash('sha256', $secret, true), 0, 32);
    }

    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        if (function_exists('sodium_crypto_secretbox') && defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES')) {
            $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = \sodium_crypto_secretbox($plaintext, $nonce, $this->keys[0]);

            return sprintf('%s:%s:%s', self::PREFIX, base64_encode($nonce), base64_encode($ciphertext));
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::OPENSSL_CIPHER, $this->keys[0], OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($ciphertext)) {
            throw new RuntimeException('Unable to encrypt secret payload.');
        }

        return sprintf('%s:%s:%s', self::PREFIX, base64_encode($iv . $tag), base64_encode($ciphertext));
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

        foreach ($this->keys as $key) {
            if (function_exists('sodium_crypto_secretbox_open') && defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES')) {
                $plaintext = \sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
                if ($plaintext !== false) {
                    return $plaintext;
                }

                continue;
            }

            if (strlen($nonce) < 28) {
                continue;
            }

            $iv = substr($nonce, 0, 12);
            $tag = substr($nonce, 12, 16);
            $plaintext = openssl_decrypt($ciphertext, self::OPENSSL_CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
            if (is_string($plaintext)) {
                return $plaintext;
            }
        }

        throw new RuntimeException('Unable to decrypt secret payload. Ensure APP_SECRET from the active app environment is loaded (routing) or add prior secrets to APP_SECRET_FALLBACKS.');
    }
}
