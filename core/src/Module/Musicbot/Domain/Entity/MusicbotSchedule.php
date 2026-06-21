<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Entity;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Enum\MusicbotScheduleAction;
use App\Repository\MusicbotScheduleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MusicbotScheduleRepository::class)]
#[ORM\Table(name: 'musicbot_schedules')]
#[ORM\Index(name: 'idx_musicbot_schedules_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_musicbot_schedules_instance', columns: ['instance_id'])]
#[ORM\Index(name: 'idx_musicbot_schedules_next_run', columns: ['enabled', 'next_run_at'])]
class MusicbotSchedule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $customer;

    #[ORM\ManyToOne(targetEntity: MusicbotInstance::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MusicbotInstance $instance;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 120)]
    private string $cronExpression;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $timezone = null;

    #[ORM\Column]
    private bool $enabled;

    #[ORM\Column(enumType: MusicbotScheduleAction::class)]
    private MusicbotScheduleAction $action;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $nextRunAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        User $customer,
        MusicbotInstance $instance,
        string $name,
        string $cronExpression,
        ?string $timezone,
        bool $enabled,
        MusicbotScheduleAction $action,
        ?array $payload = null,
    ) {
        $this->customer = $customer;
        $this->instance = $instance;
        $this->name = $name;
        $this->cronExpression = $cronExpression;
        $this->timezone = $timezone;
        $this->enabled = $enabled;
        $this->action = $action;
        $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getCustomer(): User { return $this->customer; }
    public function getInstance(): MusicbotInstance { return $this->instance; }
    public function getName(): string { return $this->name; }
    public function getCronExpression(): string { return $this->cronExpression; }
    public function getTimezone(): ?string { return $this->timezone; }
    public function isEnabled(): bool { return $this->enabled; }
    public function getAction(): MusicbotScheduleAction { return $this->action; }
    public function getPayload(): ?array { return $this->payload; }
    public function getLastRunAt(): ?\DateTimeImmutable { return $this->lastRunAt; }
    public function getNextRunAt(): ?\DateTimeImmutable { return $this->nextRunAt; }
    public function getLastError(): ?string { return $this->lastError; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function update(
        string $name,
        string $cronExpression,
        ?string $timezone,
        bool $enabled,
        MusicbotScheduleAction $action,
        ?array $payload,
    ): void {
        $this->name = $name;
        $this->cronExpression = $cronExpression;
        $this->timezone = $timezone;
        $this->enabled = $enabled;
        $this->action = $action;
        $this->payload = $payload;
        $this->touch();
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
        $this->touch();
    }

    public function setNextRunAt(?\DateTimeImmutable $nextRunAt): void
    {
        $this->nextRunAt = $nextRunAt;
        $this->touch();
    }

    public function markExecuted(\DateTimeImmutable $ranAt, ?\DateTimeImmutable $nextRunAt): void
    {
        $this->lastRunAt = $ranAt;
        $this->nextRunAt = $nextRunAt;
        $this->lastError = null;
        $this->touch();
    }

    public function markFailed(\DateTimeImmutable $ranAt, string $error, ?\DateTimeImmutable $nextRunAt): void
    {
        $this->lastRunAt = $ranAt;
        $this->nextRunAt = $nextRunAt;
        $this->lastError = $error;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
