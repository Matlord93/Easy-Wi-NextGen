<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Incident;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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
        return $this->createQueryBuilder('incident')
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
    }
}
