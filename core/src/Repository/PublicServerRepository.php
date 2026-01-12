<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PublicServer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class PublicServerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicServer::class);
    }

    /**
     * @return PublicServer[]
     */
    public function findDueForCheck(\DateTimeImmutable $now, int $limit): array
    {
        return $this->createQueryBuilder('server')
            ->andWhere('server.nextCheckAt IS NULL OR server.nextCheckAt <= :now')
            ->setParameter('now', $now)
            ->orderBy('server.nextCheckAt', 'ASC')
            ->addOrderBy('server.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return PublicServer[]
     */
    public function findVisiblePublicBySite(
        int $siteId,
        ?string $gameKey = null,
        ?string $search = null,
        ?int $limit = null,
    ): array {
        $builder = $this->createQueryBuilder('server')
            ->andWhere('server.siteId = :siteId')
            ->andWhere('server.visiblePublic = true')
            ->setParameter('siteId', $siteId)
            ->orderBy('server.sortOrder', 'ASC')
            ->addOrderBy('server.name', 'ASC');

        if ($gameKey !== null && $gameKey !== '') {
            $builder->andWhere('server.gameKey = :gameKey')
                ->setParameter('gameKey', $gameKey);
        }

        if ($search !== null && $search !== '') {
            $builder->andWhere('server.name LIKE :search OR server.gameKey LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        if ($limit !== null) {
            $builder->setMaxResults($limit);
        }

        return $builder->getQuery()->getResult();
    }

    /**
     * @return string[]
     */
    public function findPublicGamesForSite(int $siteId): array
    {
        $rows = $this->createQueryBuilder('server')
            ->select('DISTINCT server.gameKey AS gameKey')
            ->andWhere('server.siteId = :siteId')
            ->andWhere('server.visiblePublic = true')
            ->setParameter('siteId', $siteId)
            ->orderBy('server.gameKey', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_values(array_filter(array_map(static fn (array $row): string => (string) $row['gameKey'], $rows)));
    }
}
