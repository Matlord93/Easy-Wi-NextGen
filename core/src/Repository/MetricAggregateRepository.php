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

}
