<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Application\JobHistoryPolicy;
use App\Module\Core\Domain\Enum\JobStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Job>
 */
final class JobRepository extends ServiceEntityRepository implements JobRepositoryInterface
{
    public function __construct(ManagerRegistry $registry, private readonly JobHistoryPolicy $history)
    {
        parent::__construct($registry, Job::class);
    }

    /**
     * @return Job[]
     */
    #[\Override]
    public function findLatest(int $limit = 50): array
    {
        // Operational consumers use these rows for action/state reconciliation.
        return EntityQueryResult::all($this->createQueryBuilder('job')
            ->andWhere('job.type <> :background')->setParameter('background', 'instance.query.check')
            ->orderBy('job.createdAt', 'DESC')->setMaxResults($limit)->getQuery(), Job::class);
    }

    #[\Override]
    public function findLatestHistory(int $limit = 50): array
    {
        return EntityQueryResult::all($this->history->filterJobs($this->createQueryBuilder('job'))
            ->orderBy('job.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery(), \App\Module\Core\Domain\Entity\Job::class);
    }

    /**
     * @return array{jobs: Job[], total: int}
     */
    #[\Override]
    public function findPaginatedLatest(int $page, int $perPage = 25): array
    {
        $query = $this->history->filterJobs($this->createQueryBuilder('job'))
            ->orderBy('job.createdAt', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery();

        /** @var list<Job> $jobs */
        $jobs = $query->getResult();

        return [
            'jobs' => $jobs,
            'total' => $this->countAll(),
        ];
    }

    #[\Override]
    public function countAll(): int
    {
        return (int) $this->history->filterJobs($this->createQueryBuilder('job'))
            ->select('COUNT(job.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    #[\Override]
    public function countByStatus(JobStatus $status): int
    {
        return (int) $this->history->filterJobs($this->createQueryBuilder('job'))
            ->select('COUNT(job.id)')
            ->andWhere('job.status = :status')
            ->setParameter('status', $status->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Job[]
     */
    #[\Override]
    public function findQueuedForDispatchForUpdate(int $limit = 20): array
    {
        $query = $this->createQueryBuilder('job')
            ->andWhere('job.status = :status')
            ->setParameter('status', JobStatus::Queued)
            ->orderBy('job.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery();

        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);

        return EntityQueryResult::all($query, \App\Module\Core\Domain\Entity\Job::class);
    }

    /**
     * @return Job[]
     */
    #[\Override]
    public function findLatestByType(string $type, int $limit = 100): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('job')
            ->andWhere('job.type = :type')
            ->setParameter('type', $type)
            ->orderBy('job.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery(), \App\Module\Core\Domain\Entity\Job::class);
    }

    #[\Override]
    public function countRunningByAgentAndTypes(string $agentId, array $types): int
    {
        if ($types === []) {
            return 0;
        }

        return (int) $this->createQueryBuilder('job')
            ->select('COUNT(job.id)')
            ->andWhere('job.status IN (:statuses)')
            ->andWhere('job.lockedBy = :agent')
            ->andWhere('job.type IN (:types)')
            ->setParameter('statuses', [JobStatus::Running->value, JobStatus::Claimed->value])
            ->setParameter('agent', $agentId)
            ->setParameter('types', $types)
            ->getQuery()
            ->getSingleScalarResult();
    }

    #[\Override]
    public function countRunningByAgent(string $agentId): int
    {
        return (int) $this->createQueryBuilder('job')
            ->select('COUNT(job.id)')
            ->andWhere('job.status IN (:statuses)')
            ->andWhere('job.lockedBy = :agent')
            ->setParameter('statuses', [JobStatus::Running->value, JobStatus::Claimed->value])
            ->setParameter('agent', $agentId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param array<int, string> $excludedTypes
     */
    #[\Override]
    public function countRunningByAgentExcludingTypes(string $agentId, array $excludedTypes): int
    {
        $builder = $this->createQueryBuilder('job')
            ->select('COUNT(job.id)')
            ->andWhere('job.status IN (:statuses)')
            ->andWhere('job.lockedBy = :agent')
            ->setParameter('statuses', [JobStatus::Running->value, JobStatus::Claimed->value])
            ->setParameter('agent', $agentId);

        if ($excludedTypes !== []) {
            $builder
                ->andWhere('job.type NOT IN (:excludedTypes)')
                ->setParameter('excludedTypes', $excludedTypes);
        }

        return (int) $builder
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Job[]
     */
    #[\Override]
    public function findRunningWithExpiredLock(\DateTimeImmutable $now, int $limit = 200): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('job')
            ->andWhere('job.status IN (:statuses)')
            ->andWhere('job.lockExpiresAt IS NOT NULL')
            ->andWhere('job.lockExpiresAt < :now')
            ->setParameter('statuses', [JobStatus::Running->value, JobStatus::Claimed->value])
            ->setParameter('now', $now, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->orderBy('job.lockExpiresAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery(), \App\Module\Core\Domain\Entity\Job::class);
    }

    #[\Override]
    public function findActiveByTypeAndPayloadField(string $type, string $field, string $value, int $limit = 40): ?Job
    {
        $jobs = $this->findLatestByType($type, $limit);
        foreach ($jobs as $job) {
            if ($job->getStatus()->isTerminal()) {
                continue;
            }
            $payload = $job->getPayload();
            if ((string) ($payload[$field] ?? '') !== $value) {
                continue;
            }

            return $job;
        }

        return null;
    }

    #[\Override]
    public function findActivePayloadValues(string $type, string $field): array
    {
        $rows = $this->createQueryBuilder('job')->select('job.payload AS payload')
            ->andWhere('job.type = :type')->andWhere('job.status IN (:statuses)')
            ->setParameter('type', $type)
            ->setParameter('statuses', [JobStatus::Queued->value, JobStatus::Claimed->value, JobStatus::Running->value])
            ->getQuery()->getArrayResult();
        $values = [];
        foreach (MetricSampleRow::map($rows, static fn (array $row): array => \App\Infrastructure\Config\JsonMap::fromValue($row['payload'] ?? null) ?? []) as $payload) {
            $value = \App\Infrastructure\Config\ScalarValue::text($payload[$field] ?? null);
            if ($value !== '') {
                $values[$value] = true;
            }
        }

        return $values;
    }

    #[\Override]
    public function findLatestByTypeAndInstanceId(string $type, int $instanceId, int $limit = 50): ?Job
    {
        $jobs = $this->findLatestByType($type, $limit);
        foreach ($jobs as $job) {
            $payload = $job->getPayload();
            if ((int) ($payload['instance_id'] ?? 0) === $instanceId) {
                return $job;
            }
        }

        return null;
    }

    #[\Override]
    public function findLatestActiveByTypeInstanceIdAndScheduleId(string $type, int $instanceId, string $scheduleId, int $limit = 100): ?Job
    {
        if ($instanceId <= 0 || $scheduleId === '') {
            return null;
        }

        foreach ($this->findLatestByType($type, $limit) as $job) {
            if ($job->getStatus()->isTerminal()) {
                continue;
            }

            $payload = $job->getPayload();
            if ((int) ($payload['instance_id'] ?? 0) !== $instanceId) {
                continue;
            }

            if ((string) ($payload['schedule_id'] ?? '') !== $scheduleId) {
                continue;
            }

            return $job;
        }

        return null;
    }

    /** @param list<string> $types */
    #[\Override]
    public function findLatestActiveByTypesAndInstanceId(array $types, int $instanceId, int $limitPerType = 40): ?Job
    {
        $active = [];
        foreach ($types as $type) {
            $jobs = $this->findLatestByType($type, $limitPerType);
            foreach ($jobs as $job) {
                $payload = $job->getPayload();
                if ((int) ($payload['instance_id'] ?? 0) !== $instanceId) {
                    continue;
                }
                if ($job->getStatus()->isTerminal()) {
                    continue;
                }
                $active[] = $job;
                break;
            }
        }

        if ($active === []) {
            return null;
        }

        usort($active, static fn (Job $a, Job $b): int => $b->getCreatedAt() <=> $a->getCreatedAt());

        return $active[0] ?? null;
    }
}
