<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgentJob>
 */
final class AgentJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentJob::class);
    }

    /**
     * @return AgentJob[]
     */
    public function findQueuedForNode(string $nodeId, int $limit = 1): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('job')
            ->andWhere('job.node = :nodeId')
            ->andWhere('job.status = :status')
            ->setParameter('nodeId', $nodeId)
            ->setParameter('status', AgentJobStatus::Queued)
            ->orderBy('job.createdAt', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery(), \App\Module\AgentOrchestrator\Domain\Entity\AgentJob::class);
    }

    public function findLatestByIdempotencyKey(string $key): ?AgentJob
    {
        return EntityQueryResult::one($this->createQueryBuilder('job')
            ->andWhere('job.idempotencyKey = :key')
            ->setParameter('key', $key)
            ->orderBy('job.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery(), \App\Module\AgentOrchestrator\Domain\Entity\AgentJob::class);
    }

    public function countRunningForNode(string $nodeId): int
    {
        return (int) $this->createQueryBuilder('job')
            ->select('COUNT(job.id)')
            ->andWhere('job.node = :nodeId')
            ->andWhere('job.status = :status')
            ->setParameter('nodeId', $nodeId)
            ->setParameter('status', AgentJobStatus::Running)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return AgentJob[]
     */
    public function findRunningBefore(\DateTimeImmutable $cutoff, int $limit = 200): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('job')
            ->andWhere('job.status = :status')
            ->andWhere('job.startedAt IS NULL OR job.startedAt < :cutoff')
            ->setParameter('status', AgentJobStatus::Running)
            ->setParameter('cutoff', $cutoff, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->orderBy('job.startedAt', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery(), \App\Module\AgentOrchestrator\Domain\Entity\AgentJob::class);
    }

    /**
     * @param string[] $types
     *
     * @return AgentJob[]
     */
    public function findLatestForNodeAndTypes(string $nodeId, array $types, int $limit = 5): array
    {
        $queryBuilder = $this->createQueryBuilder('job')
            ->andWhere('job.node = :nodeId')
            ->setParameter('nodeId', $nodeId)
            ->orderBy('job.createdAt', 'DESC')
            ->setMaxResults(max(1, $limit));

        if ($types !== []) {
            $queryBuilder
                ->andWhere('job.type IN (:types)')
                ->setParameter('types', $types);
        }

        return EntityQueryResult::all($queryBuilder->getQuery(), \App\Module\AgentOrchestrator\Domain\Entity\AgentJob::class);
    }
}
