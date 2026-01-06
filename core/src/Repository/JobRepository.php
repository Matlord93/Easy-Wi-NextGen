<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Job;
use App\Enum\JobStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
