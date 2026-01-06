<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserSession;
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
}
