<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\AbuseLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AbuseLog>
 */
final class AbuseLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AbuseLog::class);
    }

    public function countByTypeAndIpSince(string $type, string $ipHash, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.type = :type')
            ->andWhere('a.ipHash = :ip')
            ->andWhere('a.createdAt >= :since')
            ->setParameter('type', $type)
            ->setParameter('ip', $ipHash)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
