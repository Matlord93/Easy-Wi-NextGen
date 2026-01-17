<?php

declare(strict_types=1);

namespace App\Module\Ports\Infrastructure\Repository;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Ports\Domain\Entity\PortBlock;
use App\Module\Ports\Domain\Entity\PortPool;
use App\Module\Core\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class PortBlockRepository extends ServiceEntityRepository
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
        if ($instances === []) {
            return [];
        }

        return $this->createQueryBuilder('block')
            ->where('block.instance IN (:instances)')
            ->setParameter('instances', $instances)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return PortBlock[]
     */
    public function findByPool(PortPool $pool): array
    {
        return $this->findBy(['pool' => $pool], ['startPort' => 'ASC']);
    }

    /**
     * @return PortBlock[]
     */
    public function findAssignedBlocks(): array
    {
        return $this->createQueryBuilder('block')
            ->where('block.instance IS NOT NULL')
            ->orderBy('block.startPort', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByInstance(Instance $instance): ?PortBlock
    {
        return $this->findOneBy(['instance' => $instance]);
    }
}
