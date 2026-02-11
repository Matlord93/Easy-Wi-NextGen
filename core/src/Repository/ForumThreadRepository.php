<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\ForumBoard;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\ForumThread;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class ForumThreadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumThread::class);
    }

    /**
     * @return array{items: list<ForumThread>, total: int}
     */
    public function paginateByBoard(ForumBoard $board, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('thread')
            ->andWhere('thread.board = :board')
            ->setParameter('board', $board)
            ->orderBy('thread.isPinned', 'DESC')
            ->addOrderBy('thread.lastActivityAt', 'DESC');

        $items = $qb
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $total = (int) $this->createQueryBuilder('thread_count')
            ->select('COUNT(thread_count.id)')
            ->andWhere('thread_count.board = :board')
            ->setParameter('board', $board)
            ->getQuery()
            ->getSingleScalarResult();

        return ['items' => $items, 'total' => $total];
    }

    /** @return list<ForumThread> */
    public function searchByQuery(Site $site, string $query, int $limit = 30): array
    {
        $needle = '%' . mb_strtolower($query) . '%';

        return $this->createQueryBuilder('thread')
            ->leftJoin('thread.board', 'board')->addSelect('board')
            ->leftJoin('App\\Module\\Core\\Domain\\Entity\\ForumPost', 'post', 'WITH', 'post.thread = thread')
            ->andWhere('thread.site = :site')
            ->andWhere('LOWER(thread.title) LIKE :needle OR LOWER(post.content) LIKE :needle')
            ->setParameter('site', $site)
            ->setParameter('needle', $needle)
            ->orderBy('thread.lastActivityAt', 'DESC')
            ->setMaxResults($limit)
            ->groupBy('thread.id')
            ->getQuery()
            ->getResult();
    }
}
