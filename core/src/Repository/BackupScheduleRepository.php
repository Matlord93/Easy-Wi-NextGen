<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\BackupSchedule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class BackupScheduleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BackupSchedule::class);
    }

    /**
     * @return BackupSchedule[]
     */
    public function findEnabledBatchAfterId(int $afterId, int $limit): array
    {
        return $this->createQueryBuilder('schedule')
            ->andWhere('schedule.enabled = :enabled')
            ->andWhere('schedule.id > :afterId')
            ->setParameter('enabled', true)
            ->setParameter('afterId', $afterId)
            ->orderBy('schedule.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
