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
        return $this->createQueryBuilder('job')
            ->andWhere('job.node = :nodeId')
            ->andWhere('job.status = :status')
            ->setParameter('nodeId', $nodeId)
            ->setParameter('status', AgentJobStatus::Queued)
            ->orderBy('job.createdAt', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    public function findLatestByIdempotencyKey(string $key): ?AgentJob
    {
        return $this->createQueryBuilder('job')
            ->andWhere('job.idempotencyKey = :key')
            ->setParameter('key', $key)
            ->orderBy('job.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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
        return $this->createQueryBuilder('job')
            ->andWhere('job.status = :status')
            ->andWhere('job.startedAt IS NULL OR job.startedAt < :cutoff')
            ->setParameter('status', AgentJobStatus::Running)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('job.startedAt', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
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

        return $queryBuilder->getQuery()->getResult();
    }
}
