<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

final class JsonMap
{
    /** @return array<string, mixed>|null */
    public static function decode(string $json): ?array
    {
        return self::object(json_decode($json, true));
    }

    /** @return array<string, string> */
    public static function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $strings = array_filter($value, 'is_string');
        $fields = [];
        foreach ($strings as $key => $string) {
            if (is_string($key)) {
                $fields[$key] = $string;
            }
        }

        return $fields;
    }

    /** @return array<string, mixed>|null */
    private static function object(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $fields = [];
        foreach (array_keys($value) as $key) {
            if (is_string($key)) {
                $fields += [$key => $value[$key]];
            }
        }

        return $fields;
    }
}
