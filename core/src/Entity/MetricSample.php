<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MetricSampleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MetricSampleRepository::class)]
#[ORM\Table(name: 'metric_samples')]
class MetricSample
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Agent $agent;

    #[ORM\Column]
    private \DateTimeImmutable $recordedAt;

    #[ORM\Column(nullable: true)]
    private ?float $cpuPercent = null;

    #[ORM\Column(nullable: true)]
    private ?float $memoryPercent = null;

    #[ORM\Column(nullable: true)]
    private ?float $diskPercent = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $netBytesSent = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $netBytesRecv = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $payload = null;

    public function __construct(
        Agent $agent,
        \DateTimeImmutable $recordedAt,
        ?float $cpuPercent,
        ?float $memoryPercent,
        ?float $diskPercent,
        ?int $netBytesSent,
        ?int $netBytesRecv,
        ?array $payload = null,
    ) {
        $this->agent = $agent;
        $this->recordedAt = $recordedAt;
        $this->cpuPercent = $cpuPercent;
        $this->memoryPercent = $memoryPercent;
        $this->diskPercent = $diskPercent;
        $this->netBytesSent = $netBytesSent;
        $this->netBytesRecv = $netBytesRecv;
        $this->payload = $payload;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAgent(): Agent
    {
        return $this->agent;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function getCpuPercent(): ?float
    {
        return $this->cpuPercent;
    }

    public function getMemoryPercent(): ?float
    {
        return $this->memoryPercent;
    }

    public function getDiskPercent(): ?float
    {
        return $this->diskPercent;
    }

    public function getNetBytesSent(): ?int
    {
        return $this->netBytesSent;
    }

    public function getNetBytesRecv(): ?int
    {
        return $this->netBytesRecv;
    }

    public function getPayload(): ?array
    {
        return $this->payload;
    }
}
