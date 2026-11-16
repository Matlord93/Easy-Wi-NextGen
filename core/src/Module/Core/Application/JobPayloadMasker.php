<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\JsonMap;
final class JobPayloadMasker
{
    private const MASK = '[redacted]';
    private const SENSITIVE_KEYS = [
        'password',
        'adminpassword',
        'databasepassword',
        'pass',
        'token',
        'secret',
        'adminsecret',
        'encryptedadminsecret',
        'encryptedonetimecredential',
        'apikey',
        'privatekey',
        'authorization',
        'cookie',
        'dkim',
        'smtppass',
        'sshkey',
        'authorizedkey',
        'authorizedkeys',
        'sftpkeys',
        'content',
        'contentbase64',
        'rconpassword',
        'serverpassword',
        'steamgslt',
        'steampassword',
        'onetimecredential',
        'newpassword',
    ];

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function maskPayload(array $payload): array
    {
        return JsonMap::fromValue($this->maskArray($payload)) ?? [];
    }

    public function maskText(string $value): string
    {
        return $this->maskString($value);
    }

    public function maskValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->maskArray($value);
        }

        if (is_string($value)) {
            return $this->maskString($value);
        }

        return $value;
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function maskArray(array $value): array
    {
        $masked = [];

        foreach (array_keys($value) as $key) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $masked[$key] = self::MASK;
                continue;
            }

            self::setField($masked, $key, $this->maskValue($value[$key]));
        }

        return $masked;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $key) ?? $key);

        return in_array($normalized, self::SENSITIVE_KEYS, true);
    }

    private function maskString(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed !== '' && (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '['))) {
            $decoded = JsonArray::decode($trimmed);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $encoded = json_encode($this->maskArray($decoded), JSON_UNESCAPED_SLASHES);
                return $encoded === false ? $value : $encoded;
            }
        }

        $pattern = '/(?P<key>"?(?:password|pass|token|secret|api[_-]?key|private[_-]?key|authorization|cookie|dkim|smtp[_-]?pass|ssh[_-]?key|content(?:_base64)?|rcon[_-]?password|server[_-]?password|steam[_-]?gslt|steam[_-]?password)"?)\s*[:=]\s*(?P<value>"[^"]*"|\'[^\']*\'|[^,\s;]+)/i';

        return preg_replace_callback($pattern, function (array $matches): string {
            $value = $matches['value'] ?? '';
            $maskedValue = $this->maskQuotedValue($value);

            return sprintf('%s: %s', $matches['key'], $maskedValue);
        }, $value) ?? $value;
    }

    private function maskQuotedValue(string $value): string
    {
        $firstChar = substr($value, 0, 1);
        $lastChar = substr($value, -1);

        if ($firstChar === '"' && $lastChar === '"') {
            return '"' . self::MASK . '"';
        }

        if ($firstChar === '\'' && $lastChar === '\'') {
            return '\'' . self::MASK . '\'';
        }

        return self::MASK;
    }
    /**
     * @template T
     * @param array<array-key,T> $fields
     * @param T $value
     */
    private static function setField(array &$fields, int|string $key, mixed $value): void
    {
        $fields[$key] = $value;
    }
}
