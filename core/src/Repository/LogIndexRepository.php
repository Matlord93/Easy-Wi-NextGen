<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Agent;
use App\Entity\LogIndex;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class LogIndexRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LogIndex::class);
    }

    public function findIdentity(
        string $source,
        string $scopeType,
        string $scopeId,
        string $logName,
        ?Agent $agent = null,
    ): ?LogIndex {
        return $this->findOneBy([
            'source' => $source,
            'scopeType' => $scopeType,
            'scopeId' => $scopeId,
            'logName' => $logName,
            'agent' => $agent,
        ]);
    }

    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        return $this->createQueryBuilder('log')
            ->delete()
            ->andWhere('log.updatedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
