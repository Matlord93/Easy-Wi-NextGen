<?php

declare(strict_types=1);

namespace App\Module\Ports\Infrastructure\Repository;

use App\Entity\Agent;
use App\Module\Ports\Domain\Entity\PortRange;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PortRange>
 */
final class PortRangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PortRange::class);
    }

    /**
     * @return PortRange[]
     */
    public function findByNode(Agent $node): array
    {
        return $this->findBy(['node' => $node], ['startPort' => 'ASC']);
    }

    /**
     * @return PortRange[]
     */
    public function findOverlaps(Agent $node, string $protocol, int $startPort, int $endPort, ?int $excludeId = null): array
    {
        $qb = $this->createQueryBuilder('range')
            ->andWhere('range.node = :node')
            ->andWhere('range.protocol = :protocol')
            ->andWhere('range.startPort <= :endPort')
            ->andWhere('range.endPort >= :startPort')
            ->setParameter('node', $node)
            ->setParameter('protocol', $protocol)
            ->setParameter('startPort', $startPort)
            ->setParameter('endPort', $endPort)
            ->orderBy('range.startPort', 'ASC');

        if ($excludeId !== null) {
            $qb->andWhere('range.id <> :excludeId')
                ->setParameter('excludeId', $excludeId);
        }

        return $qb->getQuery()->getResult();
    }
}
