<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Agent;
use App\Entity\DdosStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DdosStatus>
 */
final class DdosStatusRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DdosStatus::class);
    }

    /**
     * @param Agent[] $nodes
     * @return DdosStatus[]
     */
    public function findByNodes(array $nodes): array
    {
        if ($nodes === []) {
            return [];
        }

        return $this->createQueryBuilder('status')
            ->where('status.node IN (:nodes)')
            ->setParameter('nodes', $nodes)
            ->getQuery()
            ->getResult();
    }
}
