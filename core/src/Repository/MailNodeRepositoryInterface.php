<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\MailNode;
use Doctrine\DBAL\LockMode;

interface MailNodeRepositoryInterface
{
    /** @return MailNode|null */
    public function find(mixed $id, LockMode|int|null $lockMode = LockMode::NONE, ?int $lockVersion = null): ?object;

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, string>|null $orderBy
     * @return MailNode|null
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object;

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, string>|null $orderBy
     * @return MailNode[]
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array;

    /** @param array<string, mixed> $criteria */
    public function count(array $criteria = []): int;
}
