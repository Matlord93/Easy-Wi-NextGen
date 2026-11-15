<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\ORM\AbstractQuery;

final class EntityQueryResult
{
    /**
     * @template T of object
     * @param class-string<T> $entityClass
     * @return list<T>
     */
    public static function all(AbstractQuery $query, string $entityClass): array
    {
        $rows = self::rows($query->getResult());
        self::validateEntities($rows, $entityClass);

        return array_values($rows);
    }

    /**
     * @template T of object
     * @param array<array-key, mixed> $rows
     * @param class-string<T> $entityClass
     * @psalm-assert array<array-key, T> $rows
     */
    private static function validateEntities(array $rows, string $entityClass): void
    {
        foreach ($rows as $row) {
            if (!$row instanceof $entityClass) {
                throw new \UnexpectedValueException(sprintf('Entity query returned %s; expected %s.', get_debug_type($row), $entityClass));
            }
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $entityClass
     * @return \Generator<int, T>
     */
    public static function iterate(AbstractQuery $query, string $entityClass): \Generator
    {
        $rows = $query->toIterable();
        $iterator = is_array($rows) ? new \ArrayIterator($rows) : new \IteratorIterator($rows);
        $iterator->rewind();
        while ($iterator->valid()) {
            yield self::entity($iterator->current(), $entityClass);
            $iterator->next();
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $entityClass
     * @return T|null
     */
    public static function one(AbstractQuery $query, string $entityClass): ?object
    {
        return self::nullableEntity($query->getOneOrNullResult(), $entityClass);
    }

    /**
     * @template T of object
     * @param class-string<T> $entityClass
     * @return T|null
     */
    private static function nullableEntity(mixed $value, string $entityClass): ?object
    {
        return $value === null ? null : self::entity($value, $entityClass);
    }

    public static function affected(AbstractQuery $query): int
    {
        return self::affectedRows($query->execute());
    }

    private static function affectedRows(mixed $value): int
    {
        $count = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($count) || $count < 0) {
            throw new \UnexpectedValueException('Mutation query did not return a valid affected-row count.');
        }

        return $count;
    }

    /** @return array<array-key, mixed> */
    private static function rows(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Entity query did not return an array.');
        }

        return $value;
    }

    /**
     * @template T of object
     * @param class-string<T> $entityClass
     * @return T
     */
    private static function entity(mixed $value, string $entityClass): object
    {
        if (!$value instanceof $entityClass) {
            throw new \UnexpectedValueException(sprintf('Entity query returned %s; expected %s.', get_debug_type($value), $entityClass));
        }

        return $value;
    }
}
