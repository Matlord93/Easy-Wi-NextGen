<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

final class EntityIdentifier
{
    /**
     * @template T of int|string
     * @param T|null $value
     * @return T
     * @psalm-pure
     */
    public static function require(int|string|null $value): int|string
    {
        if ($value === null) {
            throw new \LogicException('A persisted entity identifier is required.');
        }

        return $value;
    }
}
