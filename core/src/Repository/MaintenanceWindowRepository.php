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
        /** @var list<MaintenanceWindow> $hydratedEntities */
        $hydratedEntities = $this->createQueryBuilder('window')
            ->andWhere('window.siteId = :siteId')
            ->andWhere('window.visiblePublic = true')
            ->andWhere('window.startAt > :now')
            ->setParameter('siteId', $siteId)
            ->setParameter('now', $now)
            ->orderBy('window.startAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $hydratedEntities;
    }

    /**
     * @return MaintenanceWindow[]
     */
    public function findCurrentPublicBySite(int $siteId, \DateTimeImmutable $now): array
    {
        /** @var list<MaintenanceWindow> $hydratedEntities */
        $hydratedEntities = $this->createQueryBuilder('window')
            ->andWhere('window.siteId = :siteId')
            ->andWhere('window.visiblePublic = true')
            ->andWhere('window.startAt <= :now')
            ->andWhere('window.endAt >= :now')
            ->setParameter('siteId', $siteId)
            ->setParameter('now', $now)
            ->orderBy('window.startAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $hydratedEntities;
    }
}
