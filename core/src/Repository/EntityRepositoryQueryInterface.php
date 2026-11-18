<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ObjectRepository;

/**
 * @template T of object
 * @extends ObjectRepository<T>
 */
interface EntityRepositoryQueryInterface extends ObjectRepository
{
    /** @return T|null */
    #[\Override]
    public function find(mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object;

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, string>|null $orderBy
     * @return T|null
     */
    #[\Override]
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object;

    public function createQueryBuilder(string $alias, ?string $indexBy = null): QueryBuilder;

    /** @param array<string, mixed> $criteria */
    public function count(array $criteria = []): int;
}
