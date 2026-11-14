<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\MaintenanceWindow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MaintenanceWindow>
 */
final class MaintenanceWindowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MaintenanceWindow::class);
    }

    /**
     * @return MaintenanceWindow[]
     */
    public function findUpcomingPublicBySite(int $siteId, \DateTimeImmutable $now): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('window')
            ->andWhere('window.siteId = :siteId')
            ->andWhere('window.visiblePublic = true')
            ->andWhere('window.startAt > :now')
            ->setParameter('siteId', $siteId)
            ->setParameter('now', $now, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->orderBy('window.startAt', 'ASC')
            ->getQuery(), \App\Module\Core\Domain\Entity\MaintenanceWindow::class);
    }

    /**
     * @return MaintenanceWindow[]
     */
    public function findCurrentPublicBySite(int $siteId, \DateTimeImmutable $now): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('window')
            ->andWhere('window.siteId = :siteId')
            ->andWhere('window.visiblePublic = true')
            ->andWhere('window.startAt <= :now')
            ->andWhere('window.endAt >= :now')
            ->setParameter('siteId', $siteId)
            ->setParameter('now', $now, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->orderBy('window.startAt', 'ASC')
            ->getQuery(), \App\Module\Core\Domain\Entity\MaintenanceWindow::class);
    }
}
