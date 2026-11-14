<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Incident;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Incident>
 */
final class IncidentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Incident::class);
    }

    /**
     * @return Incident[]
     */
    public function findCurrentPublicBySite(int $siteId, \DateTimeImmutable $now): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('incident')
            ->andWhere('incident.siteId = :siteId')
            ->andWhere('incident.visiblePublic = true')
            ->andWhere('incident.startedAt <= :now')
            ->andWhere('incident.status != :resolved')
            ->setParameter('siteId', $siteId)
            ->setParameter('now', $now, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->setParameter('resolved', 'resolved')
            ->orderBy('incident.startedAt', 'DESC')
            ->getQuery(), \App\Module\Core\Domain\Entity\Incident::class);
    }
}
