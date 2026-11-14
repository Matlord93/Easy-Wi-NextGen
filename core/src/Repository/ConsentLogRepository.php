<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\ConsentLog;
use App\Module\Core\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConsentLog>
 */
final class ConsentLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConsentLog::class);
    }

    /**
     * @return ConsentLog[]
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['acceptedAt' => 'DESC']);
    }

    public function redactByUser(User $user, string $ip, string $userAgent): int
    {
        return EntityQueryResult::affected($this->createQueryBuilder('log')
            ->update()
            ->set('log.ip', ':ip')
            ->set('log.userAgent', ':userAgent')
            ->andWhere('log.user = :user')
            ->setParameter('ip', $ip)
            ->setParameter('userAgent', $userAgent)
            ->setParameter('user', QueryParameter::entityId($user->getId(), \App\Module\Core\Domain\Entity\User::class))
            ->getQuery());
    }
}
