<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\Webspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WebspaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Webspace::class);
    }

    /**
     * @return Webspace[]
     */
    public function findByCustomer(User $customer, int $limit = 100): array
    {
        return $this->createQueryBuilder('webspace')
            ->andWhere('webspace.customer = :customer')
            ->andWhere('webspace.status != :deletedStatus')
            ->setParameter('customer', $customer)
            ->setParameter('deletedStatus', Webspace::STATUS_DELETED)
            ->orderBy('webspace.createdAt', 'DESC')
            ->setMaxResults(max(1, min(500, $limit)))
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


    /**
     * @return array{items: Webspace[], total: int, page: int, per_page: int}
     */
    public function findPaginated(int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));

        $query = $this->createQueryBuilder('webspace')
            ->andWhere('webspace.status != :deletedStatus')
            ->setParameter('deletedStatus', Webspace::STATUS_DELETED)
            ->orderBy('webspace.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery();

        $items = $query->getResult();

        $total = (int) $this->createQueryBuilder('webspace')
            ->select('COUNT(webspace.id)')
            ->andWhere('webspace.status != :deletedStatus')
            ->setParameter('deletedStatus', Webspace::STATUS_DELETED)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    public function countProvisioned(): int
    {
        return (int) $this->createQueryBuilder('webspace')
            ->select('COUNT(webspace.id)')
            ->andWhere('webspace.status != :deletedStatus')
            ->setParameter('deletedStatus', Webspace::STATUS_DELETED)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
