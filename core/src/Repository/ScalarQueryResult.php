<?php

declare(strict_types=1);

namespace App\Repository;

final class ScalarQueryResult
{
    /** @return array<array-key, mixed> */
    public static function row(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Scalar query did not return a row.');
        }

        return $value;
    }

    /** @return array<array-key, mixed>|null */
    public static function nullableRow(mixed $value): ?array
    {
        return $value === null ? null : self::row($value);
    }

    /** @return list<array<array-key, mixed>> */
    public static function rows(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Scalar query did not return rows.');
        }
        $rows = [];
        foreach (array_keys($value) as $key) {
            $rows[] = self::row($value[$key]);
        }

        return $rows;
    }

    public static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
