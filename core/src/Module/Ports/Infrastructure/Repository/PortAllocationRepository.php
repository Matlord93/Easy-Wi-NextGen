<?php

declare(strict_types=1);

namespace App\Module\Ports\Infrastructure\Repository;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Ports\Domain\Entity\PortAllocation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class PortAllocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PortAllocation::class);
    }

    /**
     * @return PortAllocation[]
     */
    public function findByInstance(Instance $instance): array
    {
        return $this->findBy(['instance' => $instance], ['id' => 'ASC']);
    }

    /**
     * @return int[]
     */
    public function findUsedPorts(Agent $node, string $proto, int $start, int $end): array
    {
        $qb = $this->createQueryBuilder('allocation');
        $qb
            ->select('allocation.port')
            ->where('allocation.node = :node')
            ->andWhere('allocation.proto = :proto')
            ->andWhere('allocation.port BETWEEN :start AND :end')
            ->setParameter('node', $node)
            ->setParameter('proto', $proto)
            ->setParameter('start', $start)
            ->setParameter('end', $end);

        return array_map('intval', array_column($qb->getQuery()->getArrayResult(), 'port'));
    }

    public function isPortAllocated(Agent $node, string $proto, int $port): bool
    {
        return $this->count(['node' => $node, 'proto' => $proto, 'port' => $port]) > 0;
    }

    /**
     * @return array<int, array{pool_tag: string, total: int, allocated: int}>
     */
    public function countAllocationsByPoolTag(Agent $node): array
    {
        $qb = $this->createQueryBuilder('allocation');
        $qb
            ->select('allocation.poolTag as pool_tag, COUNT(allocation.id) as allocated')
            ->where('allocation.node = :node')
            ->setParameter('node', $node)
            ->groupBy('allocation.poolTag');

        $results = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $results[] = [
                'pool_tag' => (string) ($row['pool_tag'] ?? ''),
                'allocated' => (int) ($row['allocated'] ?? 0),
            ];
        }

        return $results;
    }
}
