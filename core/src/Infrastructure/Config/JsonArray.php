<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

final class JsonArray
{
    /** @return array<array-key, mixed>|null */
    public static function decode(string $json): ?array
    {
        return self::fromValue(json_decode($json, true));
    }

    /** @return array<array-key, mixed>|null */
    public static function fromValue(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }
    /** @return array<array-key, mixed> */
    public static function orEmpty(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

}
