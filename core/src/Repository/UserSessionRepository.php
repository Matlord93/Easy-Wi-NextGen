<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\UserSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class UserSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserSession::class);
    }

    public function findActiveByTokenHash(string $tokenHash): ?UserSession
    {
        $session = $this->findOneBy(['tokenHash' => $tokenHash]);
        if ($session === null) {
            return null;
        }

        if ($session->isRevoked()) {
            return null;
        }

        $expiresAt = $session->getExpiresAt();
        if ($expiresAt !== null && $expiresAt <= new \DateTimeImmutable()) {
            return null;
        }

        return $session;
    }

    public function deleteExpiredBefore(\DateTimeImmutable $cutoff): int
    {
        return $this->createQueryBuilder('session')
            ->delete()
            ->andWhere('session.expiresAt IS NOT NULL')
            ->andWhere('session.expiresAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }

    public function deleteByUser(User $user): int
    {
        return $this->createQueryBuilder('session')
            ->delete()
            ->andWhere('session.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /**
     * Returns all non-revoked, non-expired sessions for a user, oldest first.
     *
     * @return UserSession[]
     */
    public function findActiveByUser(User $user): array
    {
        $now = new \DateTimeImmutable();

        return $this->createQueryBuilder('session')
            ->andWhere('session.user = :user')
            ->andWhere('session.revokedAt IS NULL')
            ->andWhere('session.expiresAt IS NULL OR session.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('now', $now)
            ->orderBy('session.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
