<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\MetricAggregate;
use App\Module\Core\Domain\Entity\MetricSample;
use App\Repository\MetricAggregateRepository;
use App\Repository\MetricSampleRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;

final class AgentMetricsIngestionService
{
    private ?bool $metricAggregateTableAvailable = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MetricAggregateRepository $aggregateRepository,
        private readonly MetricSampleRepository $metricSampleRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /** @param list<array<string,mixed>> $metricRows */
    public function ingestBatch(Agent $agent, array $metricRows): int
    {
        $latestRecordedAt = $this->metricSampleRepository->findLatestRecordedAtForAgent($agent);
        $ingested = 0;
        foreach ($metricRows as $row) {
            $sample = $this->buildSample($agent, $row);
            if ($sample === null) {
                continue;
            }

            if ($latestRecordedAt !== null && $sample->getRecordedAt() < $latestRecordedAt->modify('+5 minutes')) {
                continue;
            }

            $this->entityManager->persist($sample);
            $this->upsertAggregates($agent, $sample);
            $latestRecordedAt = $sample->getRecordedAt();
            $ingested++;
        }

        return $ingested;
    }

    public function resolveStatus(Agent $agent, int $graceSeconds, float $warn = 85.0, float $critical = 95.0): string
    {
        $last = $agent->getLastHeartbeatAt();
        if ($last === null || $last < new DateTimeImmutable(sprintf('-%d seconds', max(5, $graceSeconds)))) {
            return 'offline';
        }

        $metrics = $agent->getLastHeartbeatStats()['metrics'] ?? null;
        if (!is_array($metrics)) {
            return 'ok';
        }

        $max = max(
            (float) ($metrics['cpu']['percent'] ?? 0),
            (float) ($metrics['memory']['percent'] ?? 0),
            (float) ($metrics['disk']['percent'] ?? 0),
        );

        if ($max >= $critical) {
            return 'critical';
        }
        if ($max >= $warn) {
            return 'warning';
        }

        return 'ok';
    }

    /** @param array<string,mixed> $metrics */
    private function buildSample(Agent $agent, array $metrics): ?MetricSample
    {
        $recordedAt = $this->parseTimestamp($metrics['collected_at'] ?? null);
        if ($recordedAt === null) {
            return null;
        }

        $cpu = is_numeric($metrics['cpu']['percent'] ?? null) ? (float) $metrics['cpu']['percent'] : null;
        $memory = is_numeric($metrics['memory']['percent'] ?? null) ? (float) $metrics['memory']['percent'] : null;
        $disk = is_numeric($metrics['disk']['percent'] ?? null) ? (float) $metrics['disk']['percent'] : null;
        $sent = is_numeric($metrics['net']['bytes_sent'] ?? null) ? (int) $metrics['net']['bytes_sent'] : null;
        $recv = is_numeric($metrics['net']['bytes_recv'] ?? null) ? (int) $metrics['net']['bytes_recv'] : null;

        return new MetricSample($agent, $recordedAt, $cpu, $memory, $disk, $sent, $recv, $metrics);
    }

    private function parseTimestamp(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function upsertAggregates(Agent $agent, MetricSample $sample): void
    {
        if (!$this->isMetricAggregateTableAvailable()) {
            return;
        }

        foreach (['1m' => 60, '5m' => 300, '1h' => 3600] as $bucket => $seconds) {
            $bucketStart = $this->truncateBucket($sample->getRecordedAt(), $seconds);
            $aggregate = $this->aggregateRepository->findOneByBucket($agent, $bucket, $bucketStart);
            if ($aggregate === null) {
                $aggregate = new MetricAggregate($agent, $bucket, $bucketStart);
                $this->entityManager->persist($aggregate);
            }
            $aggregate->ingest($sample->getCpuPercent(), $sample->getMemoryPercent(), $sample->getDiskPercent());
        }
    }

    private function isMetricAggregateTableAvailable(): bool
    {
        if ($this->metricAggregateTableAvailable !== null) {
            return $this->metricAggregateTableAvailable;
        }

        try {
            $schemaManager = $this->entityManager->getConnection()->createSchemaManager();
            $this->metricAggregateTableAvailable = $schemaManager->tablesExist(['metric_aggregates']);
        } catch (DbalException) {
            $this->metricAggregateTableAvailable = false;
        }

        return $this->metricAggregateTableAvailable;
    }

    private function truncateBucket(DateTimeImmutable $dt, int $seconds): DateTimeImmutable
    {
        $ts = $dt->getTimestamp();
        return (new DateTimeImmutable('@' . ($ts - ($ts % $seconds))))->setTimezone(new DateTimeZone('UTC'));
    }
}
