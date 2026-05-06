<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\ScheduledTaskRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ScheduledTaskRun> */
final class ScheduledTaskRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduledTaskRun::class);
    }

    public function findLatestRun(): ?ScheduledTaskRun
    {
        return $this->findOneBy([], ['startedAt' => 'DESC']);
    }

    public function countFailedSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('run')
            ->select('COUNT(run.id)')
            ->andWhere('run.startedAt >= :since')
            ->andWhere('run.status = :status')
            ->setParameter('since', $since)
            ->setParameter('status', 'failed')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countCreatedJobsSince(\DateTimeImmutable $since): int
    {
        $runs = $this->createQueryBuilder('run')
            ->andWhere('run.startedAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($runs as $run) {
            if (!$run instanceof ScheduledTaskRun) {
                continue;
            }
            $count += count($run->getCreatedJobIds());
        }

        return $count;
    }

    /** @return ScheduledTaskRun[] */
    public function findRecent(int $limit = 100): array
    {
        return $this->findBy([], ['startedAt' => 'DESC'], max(1, $limit));
    }

    /** @return ScheduledTaskRun[] */
    public function findRecentForSchedule(string $source, string $scheduleId, int $limit = 50): array
    {
        return $this->findBy(['scheduleSource' => $source, 'scheduleId' => $scheduleId], ['startedAt' => 'DESC'], max(1, $limit));
    }
}
