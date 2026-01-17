<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

final class JobPayloadMasker
{
    private const MASK = '[redacted]';
    private const SENSITIVE_KEYS = [
        'password',
        'pass',
        'token',
        'secret',
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
    ];

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function maskPayload(array $payload): array
    {
        $masked = $this->maskValue($payload);

        return is_array($masked) ? $masked : [];
    }

    public function maskText(string $value): string
    {
        return $this->maskString($value);
    }

    /**
     * @return mixed
     */
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

        foreach ($value as $key => $item) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $masked[$key] = self::MASK;
                continue;
            }

            $masked[$key] = $this->maskValue($item);
        }

        return $masked;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9]/', '', $key));

        return in_array($normalized, self::SENSITIVE_KEYS, true);
    }

    private function maskString(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed !== '' && (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '['))) {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $masked = $this->maskValue($decoded);

                return json_encode($masked, JSON_UNESCAPED_SLASHES) ?: $value;
            }
        }

        $pattern = '/(?P<key>"?(?:password|pass|token|secret|api[_-]?key|private[_-]?key|authorization|cookie|dkim|smtp[_-]?pass|ssh[_-]?key)"?)\s*[:=]\s*(?P<value>"[^"]*"|\'[^\']*\'|[^,\s;]+)/i';

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
}
