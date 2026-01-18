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
}
