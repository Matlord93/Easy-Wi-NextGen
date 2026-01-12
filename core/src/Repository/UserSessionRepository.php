<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserSession;
use App\Entity\User;
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
}
