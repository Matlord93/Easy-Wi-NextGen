<?php

declare(strict_types=1);

namespace App\Infrastructure\Runtime;

final class MemoryLimit
{
    public const MINIMUM_BYTES = 512 * 1024 * 1024;
    public const MINIMUM_INI_VALUE = '512M';

    public static function ensureMinimum(): void
    {
        $current = ini_get('memory_limit');
        if ($current === false) {
            return;
        }

        $bytes = self::toBytes($current);
        if ($bytes === null || $bytes === -1 || $bytes >= self::MINIMUM_BYTES) {
            return;
        }

        ini_set('memory_limit', self::MINIMUM_INI_VALUE);
    }

    public static function toBytes(string $value): ?int
    {
        $value = trim($value);
        if ($value === '-1') {
            return -1;
        }

        if (!preg_match('/^(\d+)\s*([KMG])?$/i', $value, $matches)) {
            return null;
        }

        $bytes = (int) $matches[1];
        $unit = strtoupper($matches[2] ?? '');

        return match ($unit) {
            'G' => $bytes * 1024 * 1024 * 1024,
            'M' => $bytes * 1024 * 1024,
            'K' => $bytes * 1024,
            default => $bytes,
        };
    }
}
