<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiToken>
 */
final class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    public function findActiveByHash(string $tokenHash): ?ApiToken
    {
        $qb = $this->createQueryBuilder('token');
        $qb->andWhere('token.tokenHash = :hash')
            ->andWhere('token.revokedAt IS NULL')
            ->andWhere('token.expiresAt IS NULL OR token.expiresAt > :now')
            ->setParameter('hash', $tokenHash)
            ->setParameter('now', new \DateTimeImmutable())
            ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }
}
