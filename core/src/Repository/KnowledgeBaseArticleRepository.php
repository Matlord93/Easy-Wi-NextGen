<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\KnowledgeBaseArticle;
use App\Enum\TicketCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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
        return $this->createQueryBuilder('article')
            ->andWhere('article.siteId = :siteId')
            ->andWhere('article.visiblePublic = true')
            ->setParameter('siteId', $siteId)
            ->orderBy('article.category', 'ASC')
            ->addOrderBy('article.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return KnowledgeBaseArticle[]
     */
    public function findVisiblePublicBySiteAndCategory(int $siteId, TicketCategory $category): array
    {
        return $this->createQueryBuilder('article')
            ->andWhere('article.siteId = :siteId')
            ->andWhere('article.visiblePublic = true')
            ->andWhere('article.category = :category')
            ->setParameter('siteId', $siteId)
            ->setParameter('category', $category)
            ->orderBy('article.title', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
