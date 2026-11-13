<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\ChangelogEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChangelogEntry>
 */
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

        /** @var list<ChangelogEntry> $hydratedEntities */
        $hydratedEntities = $queryBuilder->getQuery()->getResult();

        return $hydratedEntities;
    }

    public function findPanelUpdateEntry(int $siteId, string $version): ?ChangelogEntry
    {
        /** @var ChangelogEntry|null $hydratedEntity */
        $hydratedEntity = $this->createQueryBuilder('entry')
            ->andWhere('entry.siteId = :siteId')
            ->andWhere('entry.version = :version')
            ->andWhere('entry.title = :title')
            ->setParameter('siteId', $siteId)
            ->setParameter('version', $version)
            ->setParameter('title', self::panelUpdateTitle($version))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $hydratedEntity;
    }

    public static function panelUpdateTitle(string $version): string
    {
        return sprintf('Panel-Update %s installiert', $version);
    }
}
