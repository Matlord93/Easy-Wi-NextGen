<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\LogIndex;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LogIndex>
 */
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
        return EntityQueryResult::affected($this->createQueryBuilder('log')
            ->delete()
            ->andWhere('log.updatedAt < :cutoff')
            ->setParameter('cutoff', $cutoff, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->getQuery());
    }

    /**
     * @param list<string> $sources
     */
    public function deleteOlderThanBySources(\DateTimeImmutable $cutoff, array $sources): int
    {
        if ($sources === []) {
            return 0;
        }

        return EntityQueryResult::affected($this->createQueryBuilder('log')
            ->delete()
            ->andWhere('log.updatedAt < :cutoff')
            ->andWhere('log.source IN (:sources)')
            ->setParameter('cutoff', $cutoff, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->setParameter('sources', array_values(array_unique($sources)))
            ->getQuery());
    }

    /**
     * @param list<string> $sources
     */
    public function deleteOlderThanExcludingSources(\DateTimeImmutable $cutoff, array $sources): int
    {
        $qb = $this->createQueryBuilder('log')
            ->delete()
            ->andWhere('log.updatedAt < :cutoff')
            ->setParameter('cutoff', $cutoff, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE);

        if ($sources !== []) {
            $qb->andWhere('log.source NOT IN (:sources)')
                ->setParameter('sources', array_values(array_unique($sources)));
        }

        return EntityQueryResult::affected($qb->getQuery());
    }
}
