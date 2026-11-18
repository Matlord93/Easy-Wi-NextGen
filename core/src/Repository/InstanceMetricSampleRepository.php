<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\InstanceMetricSample;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InstanceMetricSample>
 */
final class InstanceMetricSampleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstanceMetricSample::class);
    }

    /**
     * @param Instance[] $instances
     * @return array<int, array{cpu_percent: ?float, mem_used_bytes: ?int, tasks_current: ?int,
     * collected_at: \DateTimeImmutable, error_code: ?string}>
     */
    public function findLatestByInstances(array $instances): array
    {
        if ($instances === []) {
            return [];
        }

        // Do not hydrate the full metric history and then discard all but one
        // row per instance in PHP. On customer dashboards that history can be
        // large enough for ArrayHydrator to exhaust the PHP memory limit.
        $latestSampleIds = $this->getEntityManager()->createQueryBuilder()
            ->select('MAX(latest.id)')
            ->from(InstanceMetricSample::class, 'latest')
            ->andWhere('latest.instance IN (:instances)')
            ->groupBy('latest.instance');

        $rows = MetricSampleRow::map($this->createQueryBuilder('sample')
            ->select(
                'IDENTITY(sample.instance) AS instance_id',
                'sample.cpuPercent AS cpu_percent',
                'sample.memUsedBytes AS mem_used_bytes',
                'sample.tasksCurrent AS tasks_current',
                'sample.collectedAt AS collected_at',
                'sample.errorCode AS error_code',
            )
            ->andWhere(sprintf('sample.id IN (%s)', $latestSampleIds->getDQL()))
            ->setParameter('instances', $instances)
            ->getQuery()
            ->getArrayResult(), static fn (array $row): array => [
                'instance_id' => MetricSampleRow::nullableInteger($row['instance_id'] ?? null),
                'cpu_percent' => MetricSampleRow::number($row['cpu_percent'] ?? null),
                'mem_used_bytes' => MetricSampleRow::nullableInteger($row['mem_used_bytes'] ?? null),
                'tasks_current' => MetricSampleRow::nullableInteger($row['tasks_current'] ?? null),
                'collected_at' => MetricSampleRow::nullableDate($row['collected_at'] ?? null) ?? new \DateTimeImmutable(),
                'error_code' => MetricSampleRow::nullableText($row['error_code'] ?? null),
            ]);

        $latest = [];
        foreach ($rows as $row) {
            $instanceId = $row['instance_id'];
            if ($instanceId === null) {
                continue;
            }

            unset($row['instance_id']);
            $latest[$instanceId] = $row;
        }

        return $latest;
    }

    public function findLatestForInstance(Instance $instance): ?InstanceMetricSample
    {
        return EntityQueryResult::one($this->createQueryBuilder('sample')
            ->andWhere('sample.instance = :instance')
            ->setParameter('instance', QueryParameter::entityId($instance->getId(), \App\Module\Core\Domain\Entity\Instance::class))
            ->orderBy('sample.collectedAt', 'DESC')
            ->addOrderBy('sample.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery(), \App\Module\Core\Domain\Entity\InstanceMetricSample::class);
    }

    /**
     * @param list<int> $instanceIds
     * @return array<int, \DateTimeImmutable>
     */
    public function findLatestCollectedAtByInstanceIds(array $instanceIds): array
    {
        $instanceIds = array_values(array_unique(array_filter($instanceIds, static fn (int $id): bool => $id > 0)));
        if ($instanceIds === []) {
            return [];
        }

        $rows = MetricSampleRow::map($this->createQueryBuilder('sample')
            ->select('IDENTITY(sample.instance) AS instance_id')
            ->addSelect('MAX(sample.collectedAt) AS max_collected_at')
            ->andWhere('sample.instance IN (:instanceIds)')
            ->setParameter('instanceIds', $instanceIds)
            ->groupBy('sample.instance')
            ->getQuery()
            ->getArrayResult(), static fn (array $row): array => [
                'instance_id' => MetricSampleRow::nullableInteger($row['instance_id'] ?? null),
                'max_collected_at' => MetricSampleRow::nullableDate($row['max_collected_at'] ?? null),
            ]);

        $latest = [];
        foreach ($rows as $row) {
            $instanceId = $row['instance_id'] ?? 0;
            $collectedAt = $row['max_collected_at'] ?? null;
            if ($instanceId <= 0 || !$collectedAt instanceof \DateTimeImmutable) {
                continue;
            }

            $latest[$instanceId] = $collectedAt;
        }

        return $latest;
    }

    /**
     * @return array{count: int, cpu_avg: ?float, cpu_max: ?float, mem_avg: ?float, mem_max: ?float}
     */
    public function fetchAggregateForInstanceSince(Instance $instance, \DateTimeImmutable $since): array
    {
        $row = MetricSampleRow::row($this->createQueryBuilder('sample')
            ->select('COUNT(sample.id) AS sample_count')
            ->addSelect('AVG(sample.cpuPercent) AS cpu_avg')
            ->addSelect('MAX(sample.cpuPercent) AS cpu_max')
            ->addSelect('AVG(sample.memUsedBytes) AS mem_avg')
            ->addSelect('MAX(sample.memUsedBytes) AS mem_max')
            ->andWhere('sample.instance = :instance')
            ->andWhere('sample.collectedAt >= :since')
            ->setParameter('instance', QueryParameter::entityId($instance->getId(), \App\Module\Core\Domain\Entity\Instance::class))
            ->setParameter('since', $since, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleResult());

        return [
            'count' => MetricSampleRow::integer($row['sample_count'] ?? 0),
            'cpu_avg' => MetricSampleRow::number($row['cpu_avg'] ?? null),
            'cpu_max' => MetricSampleRow::number($row['cpu_max'] ?? null),
            'mem_avg' => MetricSampleRow::number($row['mem_avg'] ?? null),
            'mem_max' => MetricSampleRow::number($row['mem_max'] ?? null),
        ];
    }

    /**
     * @return array<int, array{id:int, collectedAt:\DateTimeImmutable, cpuPercent:?float,
     * memUsedBytes:?int, tasksCurrent:?int, errorCode:?string}>
     */
    public function findRecentForInstanceSince(Instance $instance, \DateTimeImmutable $since, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));

        return MetricSampleRow::map($this->createQueryBuilder('sample')
            ->select(
                'sample.id AS id',
                'sample.collectedAt AS collectedAt',
                'sample.cpuPercent AS cpuPercent',
                'sample.memUsedBytes AS memUsedBytes',
                'sample.tasksCurrent AS tasksCurrent',
                'sample.errorCode AS errorCode',
            )
            ->andWhere('sample.instance = :instance')
            ->andWhere('sample.collectedAt >= :since')
            ->setParameter('instance', QueryParameter::entityId($instance->getId(), \App\Module\Core\Domain\Entity\Instance::class))
            ->setParameter('since', $since, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->orderBy('sample.collectedAt', 'DESC')
            ->addOrderBy('sample.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getArrayResult(), static fn (array $row): array => [
                'id' => MetricSampleRow::integer($row['id'] ?? null),
                'collectedAt' => MetricSampleRow::date($row['collectedAt'] ?? null),
                'cpuPercent' => MetricSampleRow::number($row['cpuPercent'] ?? null),
                'memUsedBytes' => MetricSampleRow::nullableInteger($row['memUsedBytes'] ?? null),
                'tasksCurrent' => MetricSampleRow::nullableInteger($row['tasksCurrent'] ?? null),
                'errorCode' => MetricSampleRow::nullableText($row['errorCode'] ?? null),
            ]);
    }

    /**
     * @return array<int, array{collectedAt:\DateTimeImmutable, cpuPercent:?float, memUsedBytes:?int}>
     */
    public function findSparklineForInstanceSince(Instance $instance, \DateTimeImmutable $since, int $limit = 240): array
    {
        return MetricSampleRow::map($this->createQueryBuilder('sample')
            ->select(
                'sample.collectedAt AS collectedAt',
                'sample.cpuPercent AS cpuPercent',
                'sample.memUsedBytes AS memUsedBytes',
            )
            ->andWhere('sample.instance = :instance')
            ->andWhere('sample.collectedAt >= :since')
            ->setParameter('instance', QueryParameter::entityId($instance->getId(), \App\Module\Core\Domain\Entity\Instance::class))
            ->setParameter('since', $since, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->orderBy('sample.collectedAt', 'ASC')
            ->setMaxResults(max(2, min(500, $limit)))
            ->getQuery()
            ->getArrayResult(), static fn (array $row): array => [
                'collectedAt' => MetricSampleRow::date($row['collectedAt'] ?? null),
                'cpuPercent' => MetricSampleRow::number($row['cpuPercent'] ?? null),
                'memUsedBytes' => MetricSampleRow::nullableInteger($row['memUsedBytes'] ?? null),
            ]);
    }

    /**
     * @return array<int, array{sample_id:int, instance_id:int, customer_id:string, customer_email:string,
     * node_id:string, node_name:?string, cpu_percent:?float, mem_used_bytes:?int, booked_cpu_cores:int,
     * booked_ram_mb:int, collected_at:\DateTimeImmutable, error_code:?string}>
     */
    public function findAdminBrowseRows(
        \DateTimeImmutable $since,
        int $page,
        int $perPage,
        ?string $nodeId,
        ?string $customer,
        ?int $instanceId,
    ): array {
        return MetricSampleRow::map($this->buildAdminBrowseQueryBuilder($since, $page, $perPage, $nodeId, $customer, $instanceId)
            ->getQuery()
            ->getArrayResult(), static fn (array $row): array => [
                'sample_id' => MetricSampleRow::integer($row['sample_id'] ?? null),
                'instance_id' => MetricSampleRow::integer($row['instance_id'] ?? null),
                'customer_id' => MetricSampleRow::text($row['customer_id'] ?? null),
                'customer_email' => MetricSampleRow::text($row['customer_email'] ?? null),
                'node_id' => MetricSampleRow::text($row['node_id'] ?? null),
                'node_name' => MetricSampleRow::nullableText($row['node_name'] ?? null),
                'cpu_percent' => MetricSampleRow::number($row['cpu_percent'] ?? null),
                'mem_used_bytes' => MetricSampleRow::nullableInteger($row['mem_used_bytes'] ?? null),
                'booked_cpu_cores' => MetricSampleRow::integer($row['booked_cpu_cores'] ?? null),
                'booked_ram_mb' => MetricSampleRow::integer($row['booked_ram_mb'] ?? null),
                'collected_at' => MetricSampleRow::date($row['collected_at'] ?? null),
                'error_code' => MetricSampleRow::nullableText($row['error_code'] ?? null),
            ]);
    }

    public function countAdminBrowseRows(\DateTimeImmutable $since, ?string $nodeId, ?string $customer, ?int $instanceId): int
    {
        $qb = $this->createQueryBuilder('sample')
            ->select('COUNT(sample.id)')
            ->join('sample.instance', 'inst')
            ->join('inst.customer', 'customer')
            ->join('inst.node', 'node')
            ->andWhere('sample.collectedAt >= :since')
            ->setParameter('since', $since, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE);

        $this->applyAdminBrowseFilters($qb, $nodeId, $customer, $instanceId);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function buildAdminBrowseQueryBuilder(
        \DateTimeImmutable $since,
        int $page,
        int $perPage,
        ?string $nodeId,
        ?string $customer,
        ?int $instanceId,
    ): QueryBuilder {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));

        $qb = $this->createQueryBuilder('sample')
            ->select(
                'sample.id AS sample_id',
                'inst.id AS instance_id',
                'customer.id AS customer_id',
                'customer.email AS customer_email',
                'node.id AS node_id',
                'node.name AS node_name',
                'sample.cpuPercent AS cpu_percent',
                'sample.memUsedBytes AS mem_used_bytes',
                'inst.cpuLimit AS booked_cpu_cores',
                'inst.ramLimit AS booked_ram_mb',
                'sample.collectedAt AS collected_at',
                'sample.errorCode AS error_code',
            )
            ->join('sample.instance', 'inst')
            ->join('inst.customer', 'customer')
            ->join('inst.node', 'node')
            ->andWhere('sample.collectedAt >= :since')
            ->setParameter('since', $since, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->orderBy('sample.collectedAt', 'DESC')
            ->addOrderBy('sample.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        $this->applyAdminBrowseFilters($qb, $nodeId, $customer, $instanceId);

        return $qb;
    }

    public function deleteOlderThan(\DateTimeImmutable $threshold, int $batch = 1000): int
    {
        return (new \App\Module\Core\Application\RetentionBatchDelete($this->getEntityManager()->getConnection()))->olderThan('instance_metric_samples', 'collected_at', $threshold->format('Y-m-d H:i:s'), $batch);
    }

    /**
     * Keep the per-instance history bounded even when an agent sends samples
     * more frequently than expected. Deletes run in small batches so cleanup
     * itself cannot create a large PHP result set.
     */
    public function deleteExcessForInstance(Instance $instance, int $maximumSamples, int $batchSize = 500): int
    {
        $maximumSamples = max(1, $maximumSamples);
        $batchSize = max(1, min(1000, $batchSize));
        $deleted = 0;

        do {
            $ids = MetricSampleRow::map($this->createQueryBuilder('sample')
                ->select('sample.id AS id')
                ->andWhere('sample.instance = :instance')
                ->setParameter('instance', QueryParameter::entityId($instance->getId(), \App\Module\Core\Domain\Entity\Instance::class))
                ->orderBy('sample.collectedAt', 'DESC')
                ->addOrderBy('sample.id', 'DESC')
                ->setFirstResult($maximumSamples)
                ->setMaxResults($batchSize)
                ->getQuery()
                ->getScalarResult(), static fn (array $row): int => MetricSampleRow::integer($row['id'] ?? null));

            if ($ids === []) {
                break;
            }

            $deleted += EntityQueryResult::affected($this->createQueryBuilder('sample')
                ->delete()
                ->andWhere('sample.id IN (:ids)')
                ->setParameter('ids', $ids)
                ->getQuery());
        } while (count($ids) === $batchSize);

        return $deleted;
    }

    private function applyAdminBrowseFilters(QueryBuilder $qb, ?string $nodeId, ?string $customer, ?int $instanceId): void
    {
        if ($nodeId !== null && trim($nodeId) !== '') {
            $qb->andWhere('node.id = :nodeId')->setParameter('nodeId', trim($nodeId));
        }

        if ($instanceId !== null && $instanceId > 0) {
            $qb->andWhere('inst.id = :instanceId')->setParameter('instanceId', $instanceId);
        }

        if ($customer !== null && trim($customer) !== '') {
            $value = trim($customer);
            $qb->andWhere('customer.id = :customerId OR customer.email LIKE :customerEmail')
                ->setParameter('customerId', $value)
                ->setParameter('customerEmail', '%'.$value.'%');
        }
    }
}
