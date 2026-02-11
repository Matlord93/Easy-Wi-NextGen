<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\InstanceMetricSampleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InstanceMetricSampleRepository::class)]
#[ORM\Table(name: 'instance_metric_samples')]
#[ORM\Index(columns: ['instance_id', 'collected_at'], name: 'idx_instance_metric_samples_instance_collected')]
class InstanceMetricSample
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Instance::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Instance $instance;

    #[ORM\Column(nullable: true)]
    private ?float $cpuPercent = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $memUsedBytes = null;

    #[ORM\Column(nullable: true)]
    private ?int $tasksCurrent = null;

    #[ORM\Column]
    private \DateTimeImmutable $collectedAt;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $errorCode = null;

    public function __construct(
        Instance $instance,
        ?float $cpuPercent,
        ?int $memUsedBytes,
        ?int $tasksCurrent,
        \DateTimeImmutable $collectedAt,
        ?string $errorCode,
    ) {
        $this->instance = $instance;
        $this->cpuPercent = $cpuPercent;
        $this->memUsedBytes = $memUsedBytes;
        $this->tasksCurrent = $tasksCurrent;
        $this->collectedAt = $collectedAt;
        $this->errorCode = $errorCode;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInstance(): Instance
    {
        return $this->instance;
    }

    public function getCpuPercent(): ?float
    {
        return $this->cpuPercent;
    }

    public function getMemUsedBytes(): ?int
    {
        return $this->memUsedBytes;
    }

    public function getTasksCurrent(): ?int
    {
        return $this->tasksCurrent;
    }

    public function getCollectedAt(): \DateTimeImmutable
    {
        return $this->collectedAt;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }
}
