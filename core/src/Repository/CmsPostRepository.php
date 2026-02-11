<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\BlogCategory;
use App\Module\Core\Domain\Entity\BlogTag;
use App\Module\Core\Domain\Entity\CmsPost;
use App\Module\Core\Domain\Entity\Site;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CmsPost>
 */
final class CmsPostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CmsPost::class);
    }

    /**
     * @return list<CmsPost>
     */
    public function findPublishedByFilters(Site $site, ?BlogCategory $category = null, ?BlogTag $tag = null, ?string $query = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.category', 'category')->addSelect('category')
            ->leftJoin('p.tags', 'tag')->addSelect('tag')
            ->andWhere('p.site = :site')
            ->andWhere('p.isPublished = :published')
            ->setParameter('site', $site)
            ->setParameter('published', true)
            ->orderBy('p.publishedAt', 'DESC')
            ->addOrderBy('p.updatedAt', 'DESC')
            ->distinct();

        if ($category instanceof BlogCategory) {
            $qb->andWhere('p.category = :category')->setParameter('category', $category);
        }

        if ($tag instanceof BlogTag) {
            $qb->join('p.tags', 't_filter')
                ->andWhere('t_filter.id = :tagId')
                ->setParameter('tagId', $tag->getId());
        }

        $query = $query !== null ? trim($query) : null;
        if ($query !== null && $query !== '') {
            $qb
                ->andWhere('p.title LIKE :q OR p.excerpt LIKE :q OR p.content LIKE :q')
                ->setParameter('q', '%' . $query . '%');
        }

        /** @var list<CmsPost> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }
}
