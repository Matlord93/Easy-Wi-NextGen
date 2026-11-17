<?php

declare(strict_types=1);

namespace App\Module\Ports\Infrastructure\Repository;

use App\Infrastructure\Config\JsonArray;

use App\Repository\QueryParameter;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Ports\Domain\Entity\PortAllocation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PortAllocation>
 */
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
     * @return PortAllocation[]
     */
    public function findByInstanceOrdered(Instance $instance): array
    {
        return $this->findBy(['instance' => $instance], ['createdAt' => 'ASC', 'port' => 'ASC']);
    }

    /**
     * @return list<array{pool_tag: string, allocated: int}>
     */
    public function countAllocationsByPoolTag(Agent $node): array
    {
        $qb = $this->createQueryBuilder('allocation');
        $qb
            ->select('allocation.poolTag as pool_tag, COUNT(allocation.id) as allocated')
            ->where('allocation.node = :node')
            ->setParameter('node', QueryParameter::entityId($node->getId(), \App\Module\Core\Domain\Entity\Agent::class))
            ->groupBy('allocation.poolTag');

        $results = [];
        $rows = $qb->getQuery()->getArrayResult();
        foreach (array_keys($rows) as $rowKey) {
            $row = JsonArray::orEmpty($rows[$rowKey]);
            $results[] = [
                'pool_tag' => (string) ($row['pool_tag'] ?? ''),
                'allocated' => (int) ($row['allocated'] ?? 0),
            ];
        }

        return $results;
    }
}
