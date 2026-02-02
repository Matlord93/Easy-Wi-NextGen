<?php

declare(strict_types=1);

namespace App\Module\Unifi\Infrastructure\Repository;

use App\Module\Unifi\Domain\Entity\UnifiAuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UnifiAuditLog>
 */
class UnifiAuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UnifiAuditLog::class);
    }

    /**
     * @return UnifiAuditLog[]
     */
    public function findLatest(int $limit = 50): array
    {
        return $this->findBy([], ['createdAt' => 'DESC'], $limit);
    }

    public function findLast(): ?UnifiAuditLog
    {
        return $this->findOneBy([], ['createdAt' => 'DESC']);
    }
}
