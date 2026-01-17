<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\AgentRegistrationToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgentRegistrationToken>
 */
final class AgentRegistrationTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentRegistrationToken::class);
    }

    public function findActiveByHash(string $tokenHash): ?AgentRegistrationToken
    {
        $qb = $this->createQueryBuilder('token');
        $qb->andWhere('token.tokenHash = :hash')
            ->andWhere('token.usedAt IS NULL')
            ->andWhere('token.expiresAt > :now')
            ->setParameter('hash', $tokenHash)
            ->setParameter('now', new \DateTimeImmutable())
            ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }
}
