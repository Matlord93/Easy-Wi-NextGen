<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\ChangelogEntry;
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
    public function findVisiblePublicBySite(int $siteId, ?int $limit = null): array
    {
        $queryBuilder = $this->createQueryBuilder('entry')
            ->andWhere('entry.siteId = :siteId')
            ->andWhere('entry.visiblePublic = true')
            ->setParameter('siteId', $siteId)
            ->orderBy('entry.publishedAt', 'DESC');

        if ($limit !== null && $limit > 0) {
            $queryBuilder->setMaxResults($limit);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    public function findPanelUpdateEntry(int $siteId, string $version): ?ChangelogEntry
    {
        return $this->createQueryBuilder('entry')
            ->andWhere('entry.siteId = :siteId')
            ->andWhere('entry.version = :version')
            ->andWhere('entry.title = :title')
            ->setParameter('siteId', $siteId)
            ->setParameter('version', $version)
            ->setParameter('title', self::panelUpdateTitle($version))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public static function panelUpdateTitle(string $version): string
    {
        return sprintf('Panel-Update %s installiert', $version);
    }
}
