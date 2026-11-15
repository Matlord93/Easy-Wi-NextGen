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
     * @return array<int, array{id: int, recordedAt: \DateTimeImmutable, cpuPercent: ?float, memoryPercent:
     * ?float, diskPercent: ?float, netBytesSent: ?int, netBytesRecv: ?int}>
     */
    public function findRecentSamplesForAgentSince(Agent $agent, \DateTimeImmutable $since, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));

        return MetricSampleRow::map($this->buildRecentSamplesQueryBuilder($agent, $since, $page, $perPage)
            ->getQuery()
            ->getArrayResult(), static fn (array $row): array => [
                'id' => MetricSampleRow::integer($row['id'] ?? null),
                'recordedAt' => MetricSampleRow::date($row['recordedAt'] ?? null),
                'cpuPercent' => MetricSampleRow::number($row['cpuPercent'] ?? null),
                'memoryPercent' => MetricSampleRow::number($row['memoryPercent'] ?? null),
                'diskPercent' => MetricSampleRow::number($row['diskPercent'] ?? null),
                'netBytesSent' => MetricSampleRow::nullableInteger($row['netBytesSent'] ?? null),
                'netBytesRecv' => MetricSampleRow::nullableInteger($row['netBytesRecv'] ?? null),
            ]);
    }

    public function findLatestRecordedAtForAgent(Agent $agent): ?\DateTimeImmutable
    {
        $row = $this->createQueryBuilder('sample')
            ->select('sample.recordedAt AS recordedAt')
            ->andWhere('sample.agent = :agent')
            ->setParameter('agent', QueryParameter::entityId($agent->getId(), \App\Module\Core\Domain\Entity\Agent::class))
            ->orderBy('sample.recordedAt', 'DESC')
            ->addOrderBy('sample.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!is_array($row)) {
            return null;
        }

        return MetricSampleRow::nullableDate($row['recordedAt'] ?? null);
    }

    /**
     * @return array{count: int, cpu_avg: ?float, cpu_max: ?float, memory_avg: ?float, memory_max: ?float,
     * disk_avg: ?float, disk_max: ?float}
     */
    public function fetchAggregateSnapshotForAgentSince(Agent $agent, \DateTimeImmutable $since): array
    {
        $row = MetricSampleRow::row($this->createQueryBuilder('sample')
            ->select('COUNT(sample.id) as sample_count')
            ->addSelect('AVG(sample.cpuPercent) as cpu_avg')
            ->addSelect('MAX(sample.cpuPercent) as cpu_max')
            ->addSelect('AVG(sample.memoryPercent) as memory_avg')
            ->addSelect('MAX(sample.memoryPercent) as memory_max')
            ->addSelect('AVG(sample.diskPercent) as disk_avg')
            ->addSelect('MAX(sample.diskPercent) as disk_max')
            ->andWhere('sample.agent = :agent')
            ->andWhere('sample.recordedAt >= :since')
            ->setParameter('agent', QueryParameter::entityId($agent->getId(), \App\Module\Core\Domain\Entity\Agent::class))
            ->setParameter('since', $since, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleResult());

        return [
            'count' => MetricSampleRow::integer($row['sample_count'] ?? 0),
            'cpu_avg' => MetricSampleRow::number($row['cpu_avg'] ?? null),
            'cpu_max' => MetricSampleRow::number($row['cpu_max'] ?? null),
            'memory_avg' => MetricSampleRow::number($row['memory_avg'] ?? null),
            'memory_max' => MetricSampleRow::number($row['memory_max'] ?? null),
            'disk_avg' => MetricSampleRow::number($row['disk_avg'] ?? null),
            'disk_max' => MetricSampleRow::number($row['disk_max'] ?? null),
        ];
    }

    /**
     * @return array<int, array{recordedAt: \DateTimeImmutable, cpuPercent: ?float, memoryPercent: ?float,
     * diskPercent: ?float, netBytesSent: ?int, netBytesRecv: ?int, payload: ?array}>
     */
    public function findSparklineSeriesForAgentSince(Agent $agent, \DateTimeImmutable $since, int $limit = 240): array
    {
        return MetricSampleRow::map($this->createQueryBuilder('sample')
            ->select(
                'sample.recordedAt AS recordedAt',
                'sample.cpuPercent AS cpuPercent',
                'sample.memoryPercent AS memoryPercent',
                'sample.diskPercent AS diskPercent',
                'sample.netBytesSent AS netBytesSent',
                'sample.netBytesRecv AS netBytesRecv',
                'sample.payload AS payload',
            )
            ->andWhere('sample.agent = :agent')
            ->andWhere('sample.recordedAt >= :since')
            ->setParameter('agent', QueryParameter::entityId($agent->getId(), \App\Module\Core\Domain\Entity\Agent::class))
            ->setParameter('since', $since, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->orderBy('sample.recordedAt', 'ASC')
            ->setMaxResults(max(2, min(500, $limit)))
            ->getQuery()
            ->getArrayResult(), static fn (array $row): array => [
                'recordedAt' => MetricSampleRow::date($row['recordedAt'] ?? null),
                'cpuPercent' => MetricSampleRow::number($row['cpuPercent'] ?? null),
                'memoryPercent' => MetricSampleRow::number($row['memoryPercent'] ?? null),
                'diskPercent' => MetricSampleRow::number($row['diskPercent'] ?? null),
                'netBytesSent' => MetricSampleRow::nullableInteger($row['netBytesSent'] ?? null),
                'netBytesRecv' => MetricSampleRow::nullableInteger($row['netBytesRecv'] ?? null),
                'payload' => MetricSampleRow::payload($row['payload'] ?? null),
            ]);
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
            ->setParameter('agent', QueryParameter::entityId($agent->getId(), \App\Module\Core\Domain\Entity\Agent::class))
            ->setParameter('since', $since, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->orderBy('sample.recordedAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);
    }
}
