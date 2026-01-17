<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Module\Core\Domain\Event\ResourceEventSource;
use App\Module\Core\Domain\Event\ResourceEventSourceTrait;
use App\Repository\BackupScheduleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BackupScheduleRepository::class)]
#[ORM\Table(name: 'backup_schedules')]
class BackupSchedule implements ResourceEventSource
{
    use ResourceEventSourceTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'schedule', targetEntity: BackupDefinition::class)]
    #[ORM\JoinColumn(nullable: false)]
    private BackupDefinition $definition;

    #[ORM\Column(length: 120)]
    private string $cronExpression;

    #[ORM\Column]
    private int $retentionDays;

    #[ORM\Column]
    private int $retentionCount;

    #[ORM\Column]
    private bool $enabled;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastQueuedAt = null;

    public function __construct(BackupDefinition $definition, string $cronExpression, int $retentionDays, int $retentionCount, bool $enabled)
    {
        $this->definition = $definition;
        $this->cronExpression = $cronExpression;
        $this->retentionDays = $retentionDays;
        $this->retentionCount = $retentionCount;
        $this->enabled = $enabled;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDefinition(): BackupDefinition
    {
        return $this->definition;
    }

    public function getCronExpression(): string
    {
        return $this->cronExpression;
    }

    public function getRetentionDays(): int
    {
        return $this->retentionDays;
    }

    public function getRetentionCount(): int
    {
        return $this->retentionCount;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getLastQueuedAt(): ?\DateTimeImmutable
    {
        return $this->lastQueuedAt;
    }

    public function setLastQueuedAt(?\DateTimeImmutable $lastQueuedAt): void
    {
        $this->lastQueuedAt = $lastQueuedAt;
        $this->touch();
    }

    public function update(string $cronExpression, int $retentionDays, int $retentionCount, bool $enabled): void
    {
        $this->cronExpression = $cronExpression;
        $this->retentionDays = $retentionDays;
        $this->retentionCount = $retentionCount;
        $this->enabled = $enabled;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
