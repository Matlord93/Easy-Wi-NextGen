<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DownloadItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class DownloadItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DownloadItem::class);
    }

    /**
     * @return DownloadItem[]
     */
    public function findVisiblePublicBySite(int $siteId): array
    {
        return $this->createQueryBuilder('item')
            ->andWhere('item.siteId = :siteId')
            ->andWhere('item.visiblePublic = true')
            ->setParameter('siteId', $siteId)
            ->orderBy('item.sortOrder', 'ASC')
            ->addOrderBy('item.title', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
