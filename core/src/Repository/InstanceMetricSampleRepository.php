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
     * @return array<int, array{cpu_percent: ?float, mem_used_bytes: ?int, tasks_current: ?int, collected_at: \DateTimeImmutable, error_code: ?string}>
     */
    public function findLatestByInstances(array $instances): array
    {
        if ($instances === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('sample')
            ->select(
                'IDENTITY(sample.instance) AS instance_id',
                'sample.cpuPercent AS cpu_percent',
                'sample.memUsedBytes AS mem_used_bytes',
                'sample.tasksCurrent AS tasks_current',
                'sample.collectedAt AS collected_at',
                'sample.errorCode AS error_code',
            )
            ->andWhere('sample.instance IN (:instances)')
            ->setParameter('instances', $instances)
            ->orderBy('sample.collectedAt', 'DESC')
            ->addOrderBy('sample.id', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $latest = [];
        foreach ($rows as $row) {
            $instanceId = is_numeric($row['instance_id'] ?? null) ? (int) $row['instance_id'] : null;
            if ($instanceId === null || isset($latest[$instanceId])) {
                continue;
            }

            $latest[$instanceId] = [
                'cpu_percent' => is_numeric($row['cpu_percent'] ?? null) ? (float) $row['cpu_percent'] : null,
                'mem_used_bytes' => is_numeric($row['mem_used_bytes'] ?? null) ? (int) $row['mem_used_bytes'] : null,
                'tasks_current' => is_numeric($row['tasks_current'] ?? null) ? (int) $row['tasks_current'] : null,
                'collected_at' => $row['collected_at'] instanceof \DateTimeImmutable ? $row['collected_at'] : new \DateTimeImmutable(),
                'error_code' => is_string($row['error_code'] ?? null) ? $row['error_code'] : null,
            ];
        }

        return $latest;
    }

    public function findLatestForInstance(Instance $instance): ?InstanceMetricSample
    {
        return $this->createQueryBuilder('sample')
            ->andWhere('sample.instance = :instance')
            ->setParameter('instance', $instance)
            ->orderBy('sample.collectedAt', 'DESC')
            ->addOrderBy('sample.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return array{count: int, cpu_avg: ?float, cpu_max: ?float, mem_avg: ?float, mem_max: ?float}
     */
    public function fetchAggregateForInstanceSince(Instance $instance, \DateTimeImmutable $since): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->createQueryBuilder('sample')
            ->select('COUNT(sample.id) AS sample_count')
            ->addSelect('AVG(sample.cpuPercent) AS cpu_avg')
            ->addSelect('MAX(sample.cpuPercent) AS cpu_max')
            ->addSelect('AVG(sample.memUsedBytes) AS mem_avg')
            ->addSelect('MAX(sample.memUsedBytes) AS mem_max')
            ->andWhere('sample.instance = :instance')
            ->andWhere('sample.collectedAt >= :since')
            ->setParameter('instance', $instance)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleResult();

        return [
            'count' => (int) ($row['sample_count'] ?? 0),
            'cpu_avg' => isset($row['cpu_avg']) ? (float) $row['cpu_avg'] : null,
            'cpu_max' => isset($row['cpu_max']) ? (float) $row['cpu_max'] : null,
            'mem_avg' => isset($row['mem_avg']) ? (float) $row['mem_avg'] : null,
            'mem_max' => isset($row['mem_max']) ? (float) $row['mem_max'] : null,
        ];
    }

    /**
     * @return array<int, array{id:int, collectedAt:\DateTimeImmutable, cpuPercent:?float, memUsedBytes:?int, tasksCurrent:?int, errorCode:?string}>
     */
    public function findRecentForInstanceSince(Instance $instance, \DateTimeImmutable $since, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));

        return $this->createQueryBuilder('sample')
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
            ->setParameter('instance', $instance)
            ->setParameter('since', $since)
            ->orderBy('sample.collectedAt', 'DESC')
            ->addOrderBy('sample.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * @return array<int, array{collectedAt:\DateTimeImmutable, cpuPercent:?float, memUsedBytes:?int}>
     */
    public function findSparklineForInstanceSince(Instance $instance, \DateTimeImmutable $since, int $limit = 240): array
    {
        return $this->createQueryBuilder('sample')
            ->select(
                'sample.collectedAt AS collectedAt',
                'sample.cpuPercent AS cpuPercent',
                'sample.memUsedBytes AS memUsedBytes',
            )
            ->andWhere('sample.instance = :instance')
            ->andWhere('sample.collectedAt >= :since')
            ->setParameter('instance', $instance)
            ->setParameter('since', $since)
            ->orderBy('sample.collectedAt', 'ASC')
            ->setMaxResults(max(2, min(500, $limit)))
            ->getQuery()
            ->getArrayResult();
    }

    public function countForInstanceSince(Instance $instance, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('sample')
            ->select('COUNT(sample.id)')
            ->andWhere('sample.instance = :instance')
            ->andWhere('sample.collectedAt >= :since')
            ->setParameter('instance', $instance)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<int, array{sample_id:int, instance_id:int, customer_id:string, customer_email:string, node_id:string, node_name:?string, cpu_percent:?float, mem_used_bytes:?int, booked_cpu_cores:int, booked_ram_mb:int, collected_at:\DateTimeImmutable, error_code:?string}>
     */
    public function findAdminBrowseRows(
        \DateTimeImmutable $since,
        int $page,
        int $perPage,
        ?string $nodeId,
        ?string $customer,
        ?int $instanceId,
    ): array {
        return $this->buildAdminBrowseQueryBuilder($since, $page, $perPage, $nodeId, $customer, $instanceId)
            ->getQuery()
            ->getArrayResult();
    }

    public function countAdminBrowseRows(\DateTimeImmutable $since, ?string $nodeId, ?string $customer, ?int $instanceId): int
    {
        $qb = $this->createQueryBuilder('sample')
            ->select('COUNT(sample.id)')
            ->join('sample.instance', 'inst')
            ->join('inst.customer', 'customer')
            ->join('inst.node', 'node')
            ->andWhere('sample.collectedAt >= :since')
            ->setParameter('since', $since);

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
            ->setParameter('since', $since)
            ->orderBy('sample.collectedAt', 'DESC')
            ->addOrderBy('sample.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        $this->applyAdminBrowseFilters($qb, $nodeId, $customer, $instanceId);

        return $qb;
    }

    public function deleteOlderThan(\DateTimeImmutable $threshold): int
    {
        return $this->createQueryBuilder('sample')
            ->delete()
            ->andWhere('sample.collectedAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();
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
