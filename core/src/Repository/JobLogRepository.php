<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Job;
use App\Entity\JobLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JobLog>
 */
final class JobLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JobLog::class);
    }

    /**
     * @return JobLog[]
     */
    public function findByJob(Job $job): array
    {
        return $this->findBy(['job' => $job], ['createdAt' => 'ASC']);
    }

    /**
     * @return JobLog[]
     */
    public function findByJobAfterId(Job $job, ?int $afterId = null, int $limit = 200): array
    {
        $builder = $this->createQueryBuilder('log')
            ->andWhere('log.job = :job')
            ->setParameter('job', $job)
            ->orderBy('log.id', 'ASC')
            ->setMaxResults($limit);

        if ($afterId !== null) {
            $builder->andWhere('log.id > :afterId')
                ->setParameter('afterId', $afterId);
        }

        return $builder->getQuery()->getResult();
    }
}
