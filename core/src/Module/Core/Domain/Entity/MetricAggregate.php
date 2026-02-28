<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\MetricAggregateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MetricAggregateRepository::class)]
#[ORM\Table(name: 'metric_aggregates')]
#[ORM\UniqueConstraint(name: 'uniq_metric_aggregate_bucket', columns: ['agent_id', 'bucket', 'bucket_start'])]
class MetricAggregate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Agent $agent;

    #[ORM\Column(length: 8)]
    private string $bucket;

    #[ORM\Column]
    private \DateTimeImmutable $bucketStart;

    #[ORM\Column]
    private int $sampleCount = 0;

    #[ORM\Column(nullable: true)]
    private ?float $cpuMin = null;

    #[ORM\Column(nullable: true)]
    private ?float $cpuAvg = null;

    #[ORM\Column(nullable: true)]
    private ?float $cpuMax = null;

    #[ORM\Column(nullable: true)]
    private ?float $memoryMin = null;

    #[ORM\Column(nullable: true)]
    private ?float $memoryAvg = null;

    #[ORM\Column(nullable: true)]
    private ?float $memoryMax = null;

    #[ORM\Column(nullable: true)]
    private ?float $diskMin = null;

    #[ORM\Column(nullable: true)]
    private ?float $diskAvg = null;

    #[ORM\Column(nullable: true)]
    private ?float $diskMax = null;

    public function __construct(Agent $agent, string $bucket, \DateTimeImmutable $bucketStart)
    {
        $this->agent = $agent;
        $this->bucket = $bucket;
        $this->bucketStart = $bucketStart;
    }

    public function getAgent(): Agent
    {
        return $this->agent;
    }
    public function getBucket(): string
    {
        return $this->bucket;
    }
    public function getBucketStart(): \DateTimeImmutable
    {
        return $this->bucketStart;
    }
    public function getSampleCount(): int
    {
        return $this->sampleCount;
    }

    public function ingest(?float $cpu, ?float $memory, ?float $disk): void
    {
        $this->sampleCount++;
        $this->cpuMin = $this->minNullable($this->cpuMin, $cpu);
        $this->cpuMax = $this->maxNullable($this->cpuMax, $cpu);
        $this->memoryMin = $this->minNullable($this->memoryMin, $memory);
        $this->memoryMax = $this->maxNullable($this->memoryMax, $memory);
        $this->diskMin = $this->minNullable($this->diskMin, $disk);
        $this->diskMax = $this->maxNullable($this->diskMax, $disk);

        $this->cpuAvg = $this->avgNullable($this->cpuAvg, $cpu);
        $this->memoryAvg = $this->avgNullable($this->memoryAvg, $memory);
        $this->diskAvg = $this->avgNullable($this->diskAvg, $disk);
    }

    private function minNullable(?float $current, ?float $value): ?float
    {
        if ($value === null) {
            return $current;
        }
        return $current === null ? $value : min($current, $value);
    }

    private function maxNullable(?float $current, ?float $value): ?float
    {
        if ($value === null) {
            return $current;
        }
        return $current === null ? $value : max($current, $value);
    }

    private function avgNullable(?float $currentAvg, ?float $value): ?float
    {
        if ($value === null) {
            return $currentAvg;
        }

        if ($currentAvg === null) {
            return $value;
        }

        $n = max(1, $this->sampleCount - 1);
        return (($currentAvg * $n) + $value) / ($n + 1);
    }
}
