<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\ScheduledTaskRunRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ScheduledTaskRunRepository::class)]
#[ORM\Table(name: 'scheduled_task_runs')]
#[ORM\Index(name: 'idx_scheduled_task_runs_schedule', columns: ['schedule_source', 'schedule_id', 'started_at'])]
#[ORM\Index(name: 'idx_scheduled_task_runs_type', columns: ['type', 'started_at'])]
class ScheduledTaskRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $scheduleSource;

    #[ORM\Column(length: 64)]
    private string $scheduleId;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(length: 120)]
    private string $type;

    #[ORM\Column(length: 80)]
    private string $module;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(length: 32)]
    private string $status = 'running';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $message = null;

    #[ORM\Column(type: 'json')]
    private array $createdJobIds = [];

    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    /**
     * @param list<string> $createdJobIds
     */
    public function __construct(string $scheduleSource, string $scheduleId, string $name, string $type, string $module, \DateTimeImmutable $startedAt, string $status = 'running', ?string $message = null, array $createdJobIds = [])
    {
        $this->scheduleSource = $scheduleSource;
        $this->scheduleId = $scheduleId;
        $this->name = $name;
        $this->type = $type;
        $this->module = $module;
        $this->startedAt = $startedAt;
        $this->status = $status;
        $this->message = $message;
        $this->createdJobIds = array_values($createdJobIds);
    }

    public function getId(): ?int { return $this->id; }
    public function getScheduleSource(): string { return $this->scheduleSource; }
    public function getScheduleId(): string { return $this->scheduleId; }
    public function getName(): string { return $this->name; }
    public function getType(): string { return $this->type; }
    public function getModule(): string { return $this->module; }
    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function getStatus(): string { return $this->status; }
    public function getMessage(): ?string { return $this->message; }
    public function getCreatedJobIds(): array { return $this->createdJobIds; }
    public function getDurationMs(): ?int { return $this->durationMs; }

    /** @param list<string> $createdJobIds */
    public function finish(string $status, ?string $message, array $createdJobIds, \DateTimeImmutable $finishedAt): void
    {
        $this->status = $status;
        $this->message = $message;
        $this->createdJobIds = array_values($createdJobIds);
        $this->finishedAt = $finishedAt;
        $this->durationMs = max(0, (int) round(((float) $finishedAt->format('U.u') - (float) $this->startedAt->format('U.u')) * 1000));
    }
}
