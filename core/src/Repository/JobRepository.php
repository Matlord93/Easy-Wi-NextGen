<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Enum\JobStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

final class JobRepository extends ServiceEntityRepository
{
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
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByStatus(JobStatus $status): int
    {
        return (int) $this->createQueryBuilder('job')
            ->select('COUNT(job.id)')
            ->andWhere('job.status = :status')
            ->setParameter('status', $status)
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
            ->andWhere('job.status = :status')
            ->andWhere('job.lockedBy = :agent')
            ->andWhere('job.type IN (:types)')
            ->setParameter('status', JobStatus::Running)
            ->setParameter('agent', $agentId)
            ->setParameter('types', $types)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countRunningByAgent(string $agentId): int
    {
        return (int) $this->createQueryBuilder('job')
            ->select('COUNT(job.id)')
            ->andWhere('job.status = :status')
            ->andWhere('job.lockedBy = :agent')
            ->setParameter('status', JobStatus::Running)
            ->setParameter('agent', $agentId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Job[]
     */
    public function findRunningWithExpiredLock(\DateTimeImmutable $now, int $limit = 200): array
    {
        return $this->createQueryBuilder('job')
            ->andWhere('job.status = :status')
            ->andWhere('job.lockExpiresAt IS NOT NULL')
            ->andWhere('job.lockExpiresAt < :now')
            ->setParameter('status', JobStatus::Running)
            ->setParameter('now', $now)
            ->orderBy('job.lockExpiresAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
