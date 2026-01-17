<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\Webspace;
use App\Module\Core\Domain\Entity\Agent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class WebspaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Webspace::class);
    }

    /**
     * @return Webspace[]
     */
    public function findByCustomer(User $customer): array
    {
        return $this->createQueryBuilder('webspace')
            ->andWhere('webspace.customer = :customer')
            ->andWhere('webspace.status != :deletedStatus')
            ->setParameter('customer', $customer)
            ->setParameter('deletedStatus', Webspace::STATUS_DELETED)
            ->orderBy('webspace.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return int[]
     */
    public function findAssignedPortsByNode(Agent $node): array
    {
        $results = $this->createQueryBuilder('webspace')
            ->select('webspace.assignedPort')
            ->andWhere('webspace.node = :node')
            ->andWhere('webspace.assignedPort IS NOT NULL')
            ->andWhere('webspace.status != :deletedStatus')
            ->setParameter('node', $node)
            ->setParameter('deletedStatus', Webspace::STATUS_DELETED)
            ->getQuery()
            ->getScalarResult();

        $ports = array_map(static function (array $row): int {
            return (int) ($row['assignedPort'] ?? 0);
        }, $results);

        return array_values(array_unique(array_filter($ports, static fn (int $port): bool => $port > 0)));
    }
}
