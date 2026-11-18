<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\ScheduledTaskRun;
use App\Module\Core\Application\JobHistoryPolicy;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ScheduledTaskRun> */
final class ScheduledTaskRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly JobHistoryPolicy $history)
    {
        parent::__construct($registry, ScheduledTaskRun::class);
    }

    public function countFailedSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('run')
            ->select('COUNT(run.id)')
            ->andWhere('run.startedAt >= :since')
            ->andWhere('run.status = :status')
            ->setParameter('since', $since, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->setParameter('status', 'failed')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countCreatedJobsSince(\DateTimeImmutable $since): int
    {
        $runs = EntityQueryResult::all($this->createQueryBuilder('run')
            ->andWhere('run.startedAt >= :since')
            ->setParameter('since', $since, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->getQuery(), \App\Module\Core\Domain\Entity\ScheduledTaskRun::class);

        $count = 0;
        foreach ($runs as $run) {
            if (!$run instanceof ScheduledTaskRun) {
                continue;
            }
            $count += count($run->getCreatedJobIds());
        }

        return $count;
    }

    public function deleteHeartbeatRunsOlderThan(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('run')
            ->delete()
            ->andWhere('run.type = :type')
            ->andWhere('run.startedAt < :cutoff')
            ->setParameter('type', 'scheduler.heartbeat')
            ->setParameter('cutoff', $cutoff, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->execute();
    }

    /** @return ScheduledTaskRun[] */
    public function findRecent(int $limit = 100): array
    {
        return EntityQueryResult::all($this->history->filterRuns($this->createQueryBuilder('run'))->orderBy('run.startedAt', 'DESC')->addOrderBy('run.id', 'DESC')->setMaxResults(max(1, $limit))->getQuery(), ScheduledTaskRun::class);
    }

    /** @return ScheduledTaskRun[] */
    public function findRecentForSchedule(string $source, string $scheduleId, int $limit = 50): array
    {
        return EntityQueryResult::all($this->history->filterRuns($this->createQueryBuilder('run'))->andWhere('run.scheduleSource = :source')->andWhere('run.scheduleId = :schedule')->setParameter('source', $source)->setParameter('schedule', $scheduleId)->orderBy('run.startedAt', 'DESC')->addOrderBy('run.id', 'DESC')->setMaxResults(max(1, $limit))->getQuery(), ScheduledTaskRun::class);
    }
}
