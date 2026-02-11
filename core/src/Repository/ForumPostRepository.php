<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\ForumPost;
use App\Module\Core\Domain\Entity\ForumThread;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class ForumPostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumPost::class);
    }

    /**
     * @return list<ForumPost>
     */
    public function findVisibleByThreadWithAuthor(ForumThread $thread): array
    {
        /** @var list<ForumPost> $rows */
        $rows = $this->createQueryBuilder('post')
            ->leftJoin('post.authorUser', 'author')->addSelect('author')
            ->andWhere('post.thread = :thread')
            ->setParameter('thread', $thread)
            ->orderBy('post.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return array{items: list<ForumPost>, total: int}
     */
    public function paginateVisibleByThreadWithAuthor(ForumThread $thread, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('post')
            ->leftJoin('post.authorUser', 'author')->addSelect('author')
            ->andWhere('post.thread = :thread')
            ->setParameter('thread', $thread)
            ->orderBy('post.createdAt', 'ASC');

        /** @var list<ForumPost> $items */
        $items = $qb
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $total = (int) $this->createQueryBuilder('post_count')
            ->select('COUNT(post_count.id)')
            ->andWhere('post_count.thread = :thread')
            ->setParameter('thread', $thread)
            ->getQuery()
            ->getSingleScalarResult();

        return ['items' => $items, 'total' => $total];
    }
}
