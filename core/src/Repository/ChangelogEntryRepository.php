<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChangelogEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class ChangelogEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChangelogEntry::class);
    }

    /**
     * @return ChangelogEntry[]
     */
    public function findVisiblePublicBySite(int $siteId): array
    {
        return $this->createQueryBuilder('entry')
            ->andWhere('entry.siteId = :siteId')
            ->andWhere('entry.visiblePublic = true')
            ->setParameter('siteId', $siteId)
            ->orderBy('entry.publishedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
