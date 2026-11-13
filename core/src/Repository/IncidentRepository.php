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
        /** @var list<Incident> $hydratedEntities */
        $hydratedEntities = $this->createQueryBuilder('incident')
            ->andWhere('incident.siteId = :siteId')
            ->andWhere('incident.visiblePublic = true')
            ->andWhere('incident.startedAt <= :now')
            ->andWhere('incident.status != :resolved')
            ->setParameter('siteId', $siteId)
            ->setParameter('now', $now)
            ->setParameter('resolved', 'resolved')
            ->orderBy('incident.startedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $hydratedEntities;
    }
}
