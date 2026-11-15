<?php

declare(strict_types=1);

namespace App\Repository;

/** Validates the scalar boundary without hydrating metric entities or their relations. */
final class MetricSampleRow
{
    /**
     * @template T
     * @param callable(array<array-key, mixed>): T $normalize
     * @return list<T>
     */
    public static function map(mixed $rows, callable $normalize): array
    {
        if (!is_array($rows)) {
            throw new \UnexpectedValueException('Metric query did not return rows.');
        }

        $result = [];
        foreach (array_keys($rows) as $key) {
            $result[] = $normalize(self::row($rows[$key]));
        }

        return $result;
    }

    /** @return array<array-key, mixed> */
    public static function row(mixed $row): array
    {
        if (!is_array($row)) {
            throw new \UnexpectedValueException('Metric query returned an invalid row.');
        }

        return $row;
    }

    public static function integer(mixed $value): int
    {
        return self::nullableInteger($value) ?? throw new \UnexpectedValueException('Metric query returned an invalid integer.');
    }

    public static function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    public static function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    public static function text(mixed $value): string
    {
        return self::nullableText($value) ?? throw new \UnexpectedValueException('Metric query returned an invalid string.');
    }

    public static function nullableText(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    public static function date(mixed $value): \DateTimeImmutable
    {
        return self::nullableDate($value) ?? throw new \UnexpectedValueException('Metric query returned an invalid date.');
    }

    public static function nullableDate(mixed $value): ?\DateTimeImmutable
    {
        return $value instanceof \DateTimeImmutable ? $value : null;
    }

    /** @return array<array-key, mixed>|null */
    public static function payload(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }
}
