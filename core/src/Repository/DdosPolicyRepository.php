<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\DdosPolicy;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DdosPolicy>
 */
final class DdosPolicyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DdosPolicy::class);
    }

    /**
     * @param Agent[] $nodes
     * @return DdosPolicy[]
     */
    public function findByNodes(array $nodes): array
    {
        if ($nodes === []) {
            return [];
        }

        return $this->createQueryBuilder('policy')
            ->where('policy.node IN (:nodes)')
            ->setParameter('nodes', $nodes)
            ->getQuery()
            ->getResult();
    }
}
