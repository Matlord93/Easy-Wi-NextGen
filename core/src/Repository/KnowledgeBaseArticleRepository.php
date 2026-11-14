<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\KnowledgeBaseArticle;
use App\Module\Core\Domain\Enum\TicketCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<KnowledgeBaseArticle>
 */
final class KnowledgeBaseArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KnowledgeBaseArticle::class);
    }

    /**
     * @return KnowledgeBaseArticle[]
     */
    public function findVisiblePublicBySite(int $siteId): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('article')
            ->andWhere('article.siteId = :siteId')
            ->andWhere('article.visiblePublic = true')
            ->setParameter('siteId', $siteId)
            ->orderBy('article.category', 'ASC')
            ->addOrderBy('article.title', 'ASC')
            ->getQuery(), \App\Module\Core\Domain\Entity\KnowledgeBaseArticle::class);
    }

    /**
     * @return KnowledgeBaseArticle[]
     */
    public function findVisiblePublicBySiteAndCategory(int $siteId, TicketCategory $category): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('article')
            ->andWhere('article.siteId = :siteId')
            ->andWhere('article.visiblePublic = true')
            ->andWhere('article.category = :category')
            ->setParameter('siteId', $siteId)
            ->setParameter('category', $category->value)
            ->orderBy('article.title', 'ASC')
            ->getQuery(), \App\Module\Core\Domain\Entity\KnowledgeBaseArticle::class);
    }
}
