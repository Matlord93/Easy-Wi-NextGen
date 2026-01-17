<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\StatusComponent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class StatusComponentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StatusComponent::class);
    }

    /**
     * @return StatusComponent[]
     */
    public function findVisiblePublicBySite(int $siteId): array
    {
        return $this->createQueryBuilder('component')
            ->andWhere('component.siteId = :siteId')
            ->andWhere('component.visiblePublic = true')
            ->setParameter('siteId', $siteId)
            ->orderBy('component.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
