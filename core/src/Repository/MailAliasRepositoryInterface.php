<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\MailAlias;
use App\Module\Core\Domain\Entity\User;
use Doctrine\DBAL\LockMode;

interface MailAliasRepositoryInterface
{
    /** @return MailAlias[] */
    public function findByCustomer(User $customer): array;

    public function findOneByAddress(string $address): ?MailAlias;

    /** @return MailAlias|null */
    public function find(mixed $id, LockMode|int|null $lockMode = LockMode::NONE, ?int $lockVersion = null): ?object;

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, string>|null $orderBy
     * @return MailAlias[]
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array;

    /** @param array<string, mixed> $criteria */
    public function count(array $criteria = []): int;
}
