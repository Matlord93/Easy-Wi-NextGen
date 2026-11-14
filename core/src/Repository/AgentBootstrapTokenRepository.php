<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\AgentBootstrapToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgentBootstrapToken>
 */
class AgentBootstrapTokenRepository extends ServiceEntityRepository
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
            ->andWhere('token.invalidatedAt IS NULL')
            ->andWhere('(token.expiresAt IS NULL OR token.expiresAt > :now)')
            ->andWhere('(token.maxAttempts = 0 OR token.attemptsCount < token.maxAttempts)')
            ->setParameter('hash', $tokenHash)
            ->setParameter('now', new \DateTimeImmutable(), \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->setMaxResults(1);

        return EntityQueryResult::one($qb->getQuery(), \App\Module\Core\Domain\Entity\AgentBootstrapToken::class);
    }

    /**
     * @return AgentBootstrapToken[]
     */
    public function findRecent(int $limit): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('token')
            ->orderBy('token.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery(), \App\Module\Core\Domain\Entity\AgentBootstrapToken::class);
    }
}
