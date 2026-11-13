<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Incident;
use App\Module\Core\Domain\Entity\IncidentUpdate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IncidentUpdate>
 */
final class IncidentUpdateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IncidentUpdate::class);
    }

    /**
     * @return IncidentUpdate[]
     */
    public function findByIncident(Incident $incident): array
    {
        /** @var list<IncidentUpdate> $hydratedEntities */
        $hydratedEntities = $this->createQueryBuilder('update')
            ->andWhere('update.incident = :incident')
            ->setParameter('incident', $incident)
            ->orderBy('update.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $hydratedEntities;
    }
}
