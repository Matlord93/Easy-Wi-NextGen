<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\ApiToken;
use App\Module\Core\Domain\Entity\User;
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
            ->setParameter('now', new \DateTimeImmutable(), \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->setMaxResults(1);

        return EntityQueryResult::one($qb->getQuery(), \App\Module\Core\Domain\Entity\ApiToken::class);
    }

    public function deleteByCustomer(User $customer): int
    {
        return EntityQueryResult::affected($this->createQueryBuilder('token')
            ->delete()
            ->andWhere('token.customer = :customer')
            ->setParameter('customer', QueryParameter::entityId($customer->getId(), \App\Module\Core\Domain\Entity\User::class))
            ->getQuery());
    }
}
