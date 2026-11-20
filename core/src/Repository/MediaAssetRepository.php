<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\MediaAsset;
use App\Module\Core\Domain\Entity\Site;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MediaAsset>
 */
final class MediaAssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MediaAsset::class);
    }

    /** @return list<MediaAsset> */
    public function findGalleryPage(Site $site, string $search, int $page): array
    {
        $query = $this->libraryQuery($site, $search, $page, intdiv(PHP_INT_MAX, 24))
            ->andWhere('asset.mime LIKE :image')
            ->setParameter('image', 'image/%');

        return EntityQueryResult::all($query->getQuery(), MediaAsset::class);
    }

    /** @return list<MediaAsset> */
    public function findImagePage(Site $site, string $search, int $page): array
    {
        $query = $this->libraryQuery($site, $search, $page)
            ->andWhere('asset.path LIKE :uploads')
            ->andWhere('asset.mime LIKE :image OR asset.mime IS NULL')
            ->setParameter('uploads', '/uploads/%')
            ->setParameter('image', 'image/%');

        return EntityQueryResult::all($query->getQuery(), MediaAsset::class);
    }

    /** @return list<MediaAsset> */
    public function findLibraryPage(Site $site, string $search, int $page): array
    {
        return EntityQueryResult::all($this->libraryQuery($site, $search, $page)->getQuery(), MediaAsset::class);
    }

    private function libraryQuery(Site $site, string $search, int $page, int $pageLimit = 1000): QueryBuilder
    {
        $query = $this->createQueryBuilder('asset')
            ->andWhere('asset.site = :site')
            ->setParameter('site', QueryParameter::entityId($site->getId(), Site::class))
            ->orderBy('asset.createdAt', 'DESC')->addOrderBy('asset.id', 'DESC')
            ->setFirstResult((max(1, min($pageLimit, $page)) - 1) * 24)->setMaxResults(25);
        if ('' !== $search) {
            $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->andWhere("LOWER(asset.title) LIKE :search ESCAPE '!' OR LOWER(asset.alt) LIKE :search ESCAPE '!' OR LOWER(asset.path) LIKE :search ESCAPE '!'")
                ->setParameter('search', $needle);
        }

        return $query;
    }
}
