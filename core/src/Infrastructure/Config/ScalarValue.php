<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

final class ScalarValue
{
    public static function text(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    public static function integer(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}
