<?php

declare(strict_types=1);

namespace App\Module\Ports\Infrastructure\Repository;

use App\Repository\EntityQueryResult;
use App\Repository\QueryParameter;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Ports\Domain\Entity\PortBlock;
use App\Module\Ports\Domain\Entity\PortPool;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PortBlock>
 */
class PortBlockRepository extends ServiceEntityRepository implements PortBlockFinderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PortBlock::class);
    }

    /**
     * @return PortBlock[]
     */
    public function findByCustomer(User $customer): array
    {
        return $this->findBy(['customer' => $customer], ['createdAt' => 'DESC']);
    }

    /**
     * @param Instance[] $instances
     *
     * @return PortBlock[]
     */
    public function findByInstances(array $instances): array
    {
        if ([] === $instances) {
            return [];
        }

        return EntityQueryResult::all($this->createQueryBuilder('block')
            ->where('block.instance IN (:instances)')
            ->setParameter('instances', $instances)
            ->getQuery(), \App\Module\Ports\Domain\Entity\PortBlock::class);
    }

    /**
     * @return PortBlock[]
     */
    public function findByNode(\App\Module\Core\Domain\Entity\Agent $node): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('block')->join('block.pool', 'pool')->where('pool.node = :node')->setParameter('node', QueryParameter::entityId($node->getId(), \App\Module\Core\Domain\Entity\Agent::class))->getQuery(), \App\Module\Ports\Domain\Entity\PortBlock::class);
    }

    /** @return list<\App\Module\Ports\Domain\Entity\PortBlock> */
    public function findByPool(PortPool $pool): array
    {
        return $this->findBy(['pool' => $pool], ['startPort' => 'ASC']);
    }

    /**
     * @return PortBlock[]
     */
    public function findAssignedBlocks(): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('block')
            ->where('block.instance IS NOT NULL')
            ->orderBy('block.startPort', 'ASC')
            ->getQuery(), \App\Module\Ports\Domain\Entity\PortBlock::class);
    }

    #[\Override]
    public function findByInstance(Instance $instance): ?PortBlock
    {
        return $this->findOneBy(['instance' => $instance]);
    }
}
