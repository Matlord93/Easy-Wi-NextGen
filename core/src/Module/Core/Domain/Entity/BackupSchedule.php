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

    #[ORM\ManyToOne(targetEntity: BackupTarget::class)]
    #[ORM\JoinColumn(name: 'backup_target_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?BackupTarget $backupTarget = null;

    #[ORM\Column(length: 120)]
    private string $cronExpression;

    #[ORM\Column]
    private int $retentionDays;

    #[ORM\Column]
    private int $retentionCount;

    #[ORM\Column]
    private bool $enabled;

    #[ORM\Column(length: 100)]
    private string $timeZone = 'UTC';

    #[ORM\Column(length: 32)]
    private string $compression = 'gzip';

    #[ORM\Column]
    private bool $stopBefore = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastQueuedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $lastStatus = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $lastErrorCode = null;

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

    public function getBackupTarget(): ?BackupTarget
    {
        return $this->backupTarget;
    }

    public function setBackupTarget(?BackupTarget $backupTarget): void
    {
        $this->backupTarget = $backupTarget;
        $this->touch();
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

    public function getTimeZone(): string
    {
        return $this->timeZone;
    }

    public function setTimeZone(string $timeZone): void
    {
        $this->timeZone = $timeZone;
        $this->touch();
    }

    public function getCompression(): string
    {
        return $this->compression;
    }

    public function setCompression(string $compression): void
    {
        $this->compression = $compression;
        $this->touch();
    }

    public function isStopBefore(): bool
    {
        return $this->stopBefore;
    }

    public function setStopBefore(bool $stopBefore): void
    {
        $this->stopBefore = $stopBefore;
        $this->touch();
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

    public function getLastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function getLastStatus(): ?string
    {
        return $this->lastStatus;
    }

    public function getLastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    public function markRun(\DateTimeImmutable $runAt, string $status, ?string $errorCode = null): void
    {
        $this->lastRunAt = $runAt;
        $this->lastStatus = $status;
        $this->lastErrorCode = $errorCode;
        $this->touch();
    }

    public function update(string $cronExpression, int $retentionDays, int $retentionCount, bool $enabled, string $timeZone = 'UTC', string $compression = 'gzip', bool $stopBefore = false): void
    {
        $this->cronExpression = $cronExpression;
        $this->retentionDays = $retentionDays;
        $this->retentionCount = $retentionCount;
        $this->enabled = $enabled;
        $this->timeZone = $timeZone;
        $this->compression = $compression;
        $this->stopBefore = $stopBefore;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
