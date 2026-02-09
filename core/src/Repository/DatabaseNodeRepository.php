<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\DatabaseNode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class DatabaseNodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DatabaseNode::class);
    }

    /**
     * @return DatabaseNode[]
     */
    public function findActiveByEngine(?string $engine = null): array
    {
        $builder = $this->createQueryBuilder('node')
            ->andWhere('node.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('node.updatedAt', 'DESC');

        if ($engine !== null && $engine !== '') {
            $builder
                ->andWhere('node.engine = :engine')
                ->setParameter('engine', $engine);
        }

        return $builder
            ->getQuery()
            ->getResult();
    }
}
