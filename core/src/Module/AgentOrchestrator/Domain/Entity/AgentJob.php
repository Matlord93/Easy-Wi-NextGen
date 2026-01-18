<?php

declare(strict_types=1);

namespace App\Module\AgentOrchestrator\Domain\Entity;

use App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus;
use App\Module\Core\Domain\Entity\Agent;
use App\Repository\AgentJobRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AgentJobRepository::class)]
#[ORM\Table(name: 'agent_jobs')]
#[ORM\Index(name: 'idx_agent_jobs_node_status', columns: ['node_id', 'status'])]
#[ORM\Index(name: 'idx_agent_jobs_idempotency', columns: ['idempotency_key'])]
class AgentJob
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Agent $node;

    #[ORM\Column(length: 120)]
    private string $type;

    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(enumType: AgentJobStatus::class)]
    private AgentJobStatus $status;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $logText = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorText = null;

    #[ORM\Column]
    private int $retries = 0;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $idempotencyKey = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $resultPayload = null;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(string $id, Agent $node, string $type, array $payload)
    {
        $this->id = $id;
        $this->node = $node;
        $this->type = $type;
        $this->payload = $payload;
        $this->status = AgentJobStatus::Queued;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getNode(): Agent
    {
        return $this->node;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getStatus(): AgentJobStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getLogText(): ?string
    {
        return $this->logText;
    }

    public function getErrorText(): ?string
    {
        return $this->errorText;
    }

    public function getRetries(): int
    {
        return $this->retries;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function getResultPayload(): ?array
    {
        return $this->resultPayload;
    }

    public function markRunning(?\DateTimeImmutable $startedAt = null): void
    {
        $this->status = AgentJobStatus::Running;
        $this->startedAt = $startedAt ?? new \DateTimeImmutable();
    }

    public function markFinished(AgentJobStatus $status, ?\DateTimeImmutable $finishedAt = null): void
    {
        $this->status = $status;
        $this->finishedAt = $finishedAt ?? new \DateTimeImmutable();
    }

    public function setLogText(?string $logText): void
    {
        $this->logText = $logText !== '' ? $logText : null;
    }

    public function setErrorText(?string $errorText): void
    {
        $this->errorText = $errorText !== '' ? $errorText : null;
    }

    public function setRetries(int $retries): void
    {
        $this->retries = max(0, $retries);
    }

    public function setIdempotencyKey(?string $idempotencyKey): void
    {
        $this->idempotencyKey = $idempotencyKey !== '' ? $idempotencyKey : null;
    }

    public function setResultPayload(?array $payload): void
    {
        $this->resultPayload = $payload === [] ? null : $payload;
    }
}
