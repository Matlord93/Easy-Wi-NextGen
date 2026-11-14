<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\ORM\ORMInvalidArgumentException;

final class QueryParameter
{
    /** @param class-string $entityClass */
    public static function entityId(int|string|null $id, string $entityClass): int|string
    {
        if ($id === null) {
            throw ORMInvalidArgumentException::invalidIdentifierBindingEntity($entityClass);
        }

        return $id;
    }
}
