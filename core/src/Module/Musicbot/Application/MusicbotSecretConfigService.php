<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Core\Application\SecretsCrypto;
use App\Module\Musicbot\Domain\Entity\MusicbotConnection;

/**
 * Handles encryption, decryption, masking and sanitization of Musicbot secrets.
 *
 * All secrets (Discord bot_token, TeamSpeak passwords, stream tokens, etc.) must be stored
 * encrypted at rest. This service is the single entry point for any read or write of secret
 * configuration values. Controllers and services MUST NOT access MusicbotConnection::getSecretConfig()
 * directly for plaintext — always go through this service.
 *
 * Encrypted format: the underlying SecretsCrypto uses "v1:<nonce>:<ciphertext>" (AES-256-GCM or
 * libsodium secretbox). Values not starting with that prefix are considered plaintext legacy data
 * and are accepted for backwards-compatibility reads, then re-encrypted on the next write.
 */
final class MusicbotSecretConfigService
{
    public const SECRET_KEYS = [
        'bot_token',
        'server_password',
        'channel_password',
        'api_secret',
        'api_key',
        'stream_token',
        'runtime_control_token',
        'webhook_secret',
    ];

    private const MASK = '********';
    private const ENCRYPTED_PREFIX = 'v1:';

    public function __construct(
        private readonly SecretsCrypto $secretsCrypto,
    ) {
    }

    /**
     * Encrypt all non-empty values in a secrets array.
     * Already-encrypted values (v1: prefix) are left untouched to avoid double-encryption.
     *
     * @param array<string, string> $rawSecrets
     * @return array<string, string>
     */
    public function encrypt(array $rawSecrets): array
    {
        $result = [];
        foreach ($rawSecrets as $key => $value) {
            $result[$key] = $value !== '' ? $this->encryptValue($value) : '';
        }

        return $result;
    }

    /**
     * Decrypt all non-empty values in an encrypted secrets array.
     * Values without the v1: prefix are treated as plaintext (legacy migration path).
     *
     * @param array<string, string> $encryptedSecrets
     * @return array<string, string>
     */
    public function decrypt(array $encryptedSecrets): array
    {
        $result = [];
        foreach ($encryptedSecrets as $key => $value) {
            $result[$key] = $value !== '' ? $this->decryptValue($value) : '';
        }

        return $result;
    }

    /**
     * Return a masked representation of a secrets array.
     * Non-empty values become '********'; empty values stay empty.
     * Safe to include in any response or log — never reveals plaintext.
     *
     * @param array<string, mixed> $secrets
     * @return array<string, string>
     */
    public function mask(array $secrets): array
    {
        $result = [];
        foreach ($secrets as $key => $value) {
            $result[(string) $key] = ($value !== '' && $value !== null) ? self::MASK : '';
        }

        return $result;
    }

    /**
     * Safe representation for API responses.
     * Functionally identical to mask() — never returns plaintext or ciphertext.
     *
     * @param array<string, mixed> $secrets
     * @return array<string, string>
     */
    public function normalizeForApi(array $secrets): array
    {
        return $this->mask($secrets);
    }

    /**
     * Decrypt secrets for agent runtime use (e.g., building a job payload that the agent needs
     * to configure the bot). The returned array contains plaintext values that MUST NOT be
     * persisted to the database or included in log output.
     *
     * @param array<string, mixed> $encryptedSecrets
     * @return array<string, string>
     */
    public function normalizeForRuntime(array $encryptedSecrets): array
    {
        $stringOnly = array_filter($encryptedSecrets, static fn (mixed $v): bool => is_string($v));

        return $this->decrypt(array_map('strval', $stringOnly));
    }

    /**
     * Re-encrypt a single secret key on a connection with a new plaintext value.
     * Use this for key rotation — every call produces a new ciphertext due to a random nonce.
     */
    public function rotateSecret(MusicbotConnection $connection, string $key, string $newPlaintext): void
    {
        $secrets = $connection->getSecretConfig();
        $secrets[$key] = $this->secretsCrypto->encrypt($newPlaintext);
        $connection->setSecretConfig($secrets);
    }

    /**
     * Merge incoming secret updates into the existing encrypted config stored in the DB.
     *
     * Rules:
     *  - If $updates[$key] is empty ('') or the sentinel mask ('********'): keep existing value.
     *  - Otherwise: encrypt the new plaintext and replace.
     *  - Keys in $existingEncrypted that are absent from $updates are left unchanged.
     *
     * @param array<string, mixed> $existingEncrypted  current raw DB values (encrypted)
     * @param array<string, mixed> $updates            user-submitted values (plaintext or empty)
     * @return array<string, string>                   merged result, ready to store in DB
     */
    public function mergeSecretUpdates(array $existingEncrypted, array $updates): array
    {
        $result = array_map('strval', $existingEncrypted);

        foreach ($updates as $key => $value) {
            $strVal = (string) $value;
            if ($strVal === '' || $strVal === self::MASK) {
                continue;
            }
            $result[(string) $key] = $this->encryptValue($strVal);
        }

        return $result;
    }

    /**
     * Strip known-secret keys from a job payload array before persisting to the database.
     * Recurses into nested arrays. Use this to sanitize AgentJob payloads or resultPayloads.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sanitizePayload(array $payload): array
    {
        $sanitized = [];
        foreach ($payload as $key => $value) {
            if ($this->isSecretKey((string) $key)) {
                continue;
            }
            $sanitized[$key] = is_array($value) ? $this->sanitizePayload($value) : $value;
        }

        return $sanitized;
    }

    /**
     * Mask known-secret patterns in free-form log or error text.
     * Handles common serialization formats: key=value, key: value, "key": "value".
     */
    public function sanitizeLogText(string $text): string
    {
        foreach (self::SECRET_KEYS as $key) {
            $pattern = '/(' . preg_quote($key, '/') . '["\s]*[:=]["\s]*)([^\s,"\'}\]\r\n]+)/i';
            $text = (string) preg_replace($pattern, '$1' . self::MASK, $text);
        }

        return $text;
    }

    /**
     * Check whether a stored value is already in the encrypted v1: format.
     */
    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::ENCRYPTED_PREFIX);
    }

    /**
     * Check whether the given array key name indicates a secret value.
     */
    public function isSecretKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SECRET_KEYS as $secretKey) {
            if (str_contains($lower, $secretKey)) {
                return true;
            }
        }

        return false;
    }

    private function encryptValue(string $value): string
    {
        if ($this->isEncrypted($value)) {
            return $value;
        }

        return $this->secretsCrypto->encrypt($value);
    }

    private function decryptValue(string $value): string
    {
        if (!$this->isEncrypted($value)) {
            return $value;
        }

        return $this->secretsCrypto->decrypt($value);
    }
}
