<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Enum\JobStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

class JobRepository extends ServiceEntityRepository
{
    /** Job types that run silently in the background and are never shown in the UI. */
    private const BACKGROUND_TYPES = ['instance.query.check'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Job::class);
    }

    /**
     * @return Job[]
     */
    public function findLatest(int $limit = 50): array
    {
        return $this->createQueryBuilder('job')
            ->andWhere('job.type NOT IN (:hidden)')
            ->setParameter('hidden', self::BACKGROUND_TYPES)
            ->orderBy('job.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{jobs: Job[], total: int}
     */
    public function findPaginatedLatest(int $page, int $perPage = 25): array
    {
        $query = $this->createQueryBuilder('job')
            ->andWhere('job.type NOT IN (:hidden)')
            ->setParameter('hidden', self::BACKGROUND_TYPES)
            ->orderBy('job.createdAt', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery();

        $paginator = new Paginator($query);

        return [
            'jobs' => iterator_to_array($paginator->getIterator()),
            'total' => count($paginator),
        ];
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('job')
            ->select('COUNT(job.id)')
            ->andWhere('job.type NOT IN (:hidden)')
            ->setParameter('hidden', self::BACKGROUND_TYPES)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByStatus(JobStatus $status): int
    {
        return (int) $this->createQueryBuilder('job')
            ->select('COUNT(job.id)')
            ->andWhere('job.status = :status')
            ->andWhere('job.type NOT IN (:hidden)')
            ->setParameter('status', $status)
            ->setParameter('hidden', self::BACKGROUND_TYPES)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Job[]
     */
    public function findQueuedForDispatch(int $limit = 20): array
    {
        return $this->createQueryBuilder('job')
            ->andWhere('job.status = :status')
            ->setParameter('status', JobStatus::Queued)
            ->orderBy('job.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Job[]
     */
    public function findLatestByType(string $type, int $limit = 100): array
    {
        return $this->createQueryBuilder('job')
            ->andWhere('job.type = :type')
            ->setParameter('type', $type)
            ->orderBy('job.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

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
    public function findRunningWithExpiredLock(\DateTimeImmutable $now, int $limit = 200): array
    {
        return $this->createQueryBuilder('job')
            ->andWhere('job.status IN (:statuses)')
            ->andWhere('job.lockExpiresAt IS NOT NULL')
            ->andWhere('job.lockExpiresAt < :now')
            ->setParameter('statuses', [JobStatus::Running->value, JobStatus::Claimed->value])
            ->setParameter('now', $now)
            ->orderBy('job.lockExpiresAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }


    public function findActiveByTypeAndDatabaseId(string $type, int $databaseId, int $limit = 40): ?Job
    {
        $jobs = $this->findLatestByType($type, $limit);
        foreach ($jobs as $job) {
            if ($job->getStatus()->isTerminal()) {
                continue;
            }
            $payload = $job->getPayload();
            if ((int) ($payload['database_id'] ?? 0) !== $databaseId) {
                continue;
            }

            return $job;
        }

        return null;
    }


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

    /**
     * @param list<string> $types
     */

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
