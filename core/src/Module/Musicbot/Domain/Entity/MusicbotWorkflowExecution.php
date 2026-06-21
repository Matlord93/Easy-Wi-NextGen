<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Entity;

use App\Module\Musicbot\Domain\Enum\MusicbotWorkflowExecutionStatus;
use App\Repository\MusicbotWorkflowExecutionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MusicbotWorkflowExecutionRepository::class)]
#[ORM\Table(name: 'musicbot_workflow_executions')]
#[ORM\Index(name: 'idx_musicbot_wf_exec_workflow', columns: ['workflow_id'])]
#[ORM\Index(name: 'idx_musicbot_wf_exec_triggered', columns: ['triggered_at'])]
#[ORM\Index(name: 'idx_musicbot_wf_exec_status', columns: ['status'])]
class MusicbotWorkflowExecution
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MusicbotWorkflow::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MusicbotWorkflow $workflow;

    #[ORM\Column]
    private \DateTimeImmutable $triggeredAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(enumType: MusicbotWorkflowExecutionStatus::class, length: 20)]
    private MusicbotWorkflowExecutionStatus $status = MusicbotWorkflowExecutionStatus::Pending;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $triggerContext = [];

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $log = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    /**
     * @param array<string, mixed> $triggerContext
     */
    public function __construct(MusicbotWorkflow $workflow, array $triggerContext = [])
    {
        $this->workflow = $workflow;
        $this->triggerContext = $triggerContext;
        $this->triggeredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getWorkflow(): MusicbotWorkflow { return $this->workflow; }
    public function getTriggeredAt(): \DateTimeImmutable { return $this->triggeredAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function getStatus(): MusicbotWorkflowExecutionStatus { return $this->status; }
    /** @return array<string, mixed> */ public function getTriggerContext(): array { return $this->triggerContext; }
    public function getLog(): ?string { return $this->log; }
    public function getError(): ?string { return $this->error; }
    public function getDurationMs(): ?int { return $this->durationMs; }

    public function markRunning(): void
    {
        $this->status = MusicbotWorkflowExecutionStatus::Running;
    }

    public function markCompleted(string $log, int $durationMs): void
    {
        $this->status = MusicbotWorkflowExecutionStatus::Completed;
        $this->log = $log;
        $this->durationMs = $durationMs;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function markFailed(string $error, string $log, int $durationMs): void
    {
        $this->status = MusicbotWorkflowExecutionStatus::Failed;
        $this->error = $error;
        $this->log = $log !== '' ? $log : null;
        $this->durationMs = $durationMs;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function markSkipped(string $reason): void
    {
        $this->status = MusicbotWorkflowExecutionStatus::Skipped;
        $this->log = $reason;
        $this->completedAt = new \DateTimeImmutable();
        $this->durationMs = 0;
    }
}
