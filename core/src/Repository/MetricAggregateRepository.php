<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\MetricAggregate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MetricAggregate>
 */
final class MetricAggregateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MetricAggregate::class);
    }

    public function findOneByBucket(Agent $agent, string $bucket, \DateTimeImmutable $bucketStart): ?MetricAggregate
    {
        return $this->findOneBy([
            'agent' => $agent,
            'bucket' => $bucket,
            'bucketStart' => $bucketStart,
        ]);
    }

    /** @return array<int, array{bucketStart:\DateTimeImmutable,cpuAvg:?float,memoryAvg:?float,diskAvg:?float}> */
    public function findSeries(Agent $agent, string $bucket, \DateTimeImmutable $since, int $limit = 500): array
    {
        return $this->createQueryBuilder('a')
            ->select('a.bucketStart as bucketStart', 'a.cpuAvg as cpuAvg', 'a.memoryAvg as memoryAvg', 'a.diskAvg as diskAvg')
            ->andWhere('a.agent = :agent')
            ->andWhere('a.bucket = :bucket')
            ->andWhere('a.bucketStart >= :since')
            ->setParameters(['agent' => $agent, 'bucket' => $bucket, 'since' => $since])
            ->orderBy('a.bucketStart', 'ASC')
            ->setMaxResults(max(10, min($limit, 2000)))
            ->getQuery()
            ->getArrayResult();
    }
}
