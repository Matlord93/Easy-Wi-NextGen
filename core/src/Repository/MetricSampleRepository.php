<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\MetricSample;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MetricSample>
 */
final class MetricSampleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MetricSample::class);
    }

    /**
     * @return array<int, array{id: int, recordedAt: \DateTimeImmutable, cpuPercent: ?float, memoryPercent: ?float, diskPercent: ?float, netBytesSent: ?int, netBytesRecv: ?int}>
     */
    public function findRecentSamplesForAgentSince(Agent $agent, \DateTimeImmutable $since, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));

        return $this->buildRecentSamplesQueryBuilder($agent, $since, $page, $perPage)
            ->getQuery()
            ->getArrayResult();
    }

    public function countSamplesForAgentSince(Agent $agent, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('sample')
            ->select('COUNT(sample.id)')
            ->andWhere('sample.agent = :agent')
            ->andWhere('sample.recordedAt >= :since')
            ->setParameter('agent', $agent)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array{count: int, cpu_avg: ?float, cpu_max: ?float, memory_avg: ?float, memory_max: ?float, disk_avg: ?float, disk_max: ?float}
     */
    public function fetchAggregateSnapshotForAgentSince(Agent $agent, \DateTimeImmutable $since): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->createQueryBuilder('sample')
            ->select('COUNT(sample.id) as sample_count')
            ->addSelect('AVG(sample.cpuPercent) as cpu_avg')
            ->addSelect('MAX(sample.cpuPercent) as cpu_max')
            ->addSelect('AVG(sample.memoryPercent) as memory_avg')
            ->addSelect('MAX(sample.memoryPercent) as memory_max')
            ->addSelect('AVG(sample.diskPercent) as disk_avg')
            ->addSelect('MAX(sample.diskPercent) as disk_max')
            ->andWhere('sample.agent = :agent')
            ->andWhere('sample.recordedAt >= :since')
            ->setParameter('agent', $agent)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleResult();

        return [
            'count' => (int) ($row['sample_count'] ?? 0),
            'cpu_avg' => isset($row['cpu_avg']) ? (float) $row['cpu_avg'] : null,
            'cpu_max' => isset($row['cpu_max']) ? (float) $row['cpu_max'] : null,
            'memory_avg' => isset($row['memory_avg']) ? (float) $row['memory_avg'] : null,
            'memory_max' => isset($row['memory_max']) ? (float) $row['memory_max'] : null,
            'disk_avg' => isset($row['disk_avg']) ? (float) $row['disk_avg'] : null,
            'disk_max' => isset($row['disk_max']) ? (float) $row['disk_max'] : null,
        ];
    }

    /**
     * @return array<int, array{recordedAt: \DateTimeImmutable, cpuPercent: ?float, memoryPercent: ?float, diskPercent: ?float, netBytesSent: ?int, netBytesRecv: ?int}>
     */
    public function findSparklineSeriesForAgentSince(Agent $agent, \DateTimeImmutable $since, int $limit = 240): array
    {
        $rows = $this->createQueryBuilder('sample')
            ->select(
                'sample.recordedAt AS recordedAt',
                'sample.cpuPercent AS cpuPercent',
                'sample.memoryPercent AS memoryPercent',
                'sample.diskPercent AS diskPercent',
                'sample.netBytesSent AS netBytesSent',
                'sample.netBytesRecv AS netBytesRecv',
            )
            ->andWhere('sample.agent = :agent')
            ->andWhere('sample.recordedAt >= :since')
            ->setParameter('agent', $agent)
            ->setParameter('since', $since)
            ->orderBy('sample.recordedAt', 'ASC')
            ->setMaxResults(max(2, min(500, $limit)))
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }

    public function buildRecentSamplesQueryBuilder(Agent $agent, \DateTimeImmutable $since, int $page, int $perPage): QueryBuilder
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));

        return $this->createQueryBuilder('sample')
            ->select(
                'sample.id AS id',
                'sample.recordedAt AS recordedAt',
                'sample.cpuPercent AS cpuPercent',
                'sample.memoryPercent AS memoryPercent',
                'sample.diskPercent AS diskPercent',
                'sample.netBytesSent AS netBytesSent',
                'sample.netBytesRecv AS netBytesRecv',
            )
            ->andWhere('sample.agent = :agent')
            ->andWhere('sample.recordedAt >= :since')
            ->setParameter('agent', $agent)
            ->setParameter('since', $since)
            ->orderBy('sample.recordedAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);
    }
}
