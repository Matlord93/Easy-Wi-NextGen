<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AgentBootstrapToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgentBootstrapToken>
 */
final class AgentBootstrapTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentBootstrapToken::class);
    }

    public function findActiveByHash(string $tokenHash): ?AgentBootstrapToken
    {
        $qb = $this->createQueryBuilder('token');
        $qb->andWhere('token.tokenHash = :hash')
            ->andWhere('token.revokedAt IS NULL')
            ->andWhere('token.usedAt IS NULL')
            ->andWhere('token.expiresAt > :now')
            ->setParameter('hash', $tokenHash)
            ->setParameter('now', new \DateTimeImmutable())
            ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @return AgentBootstrapToken[]
     */
    public function findRecent(int $limit): array
    {
        return $this->createQueryBuilder('token')
            ->orderBy('token.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
