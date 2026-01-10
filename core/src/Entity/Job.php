<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\JobStatus;
use App\Repository\JobRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: JobRepository::class)]
#[ORM\Table(name: 'jobs')]
class Job
{
    private const ALLOWED_TRANSITIONS = [
        JobStatus::Queued->value => [JobStatus::Running, JobStatus::Cancelled],
        JobStatus::Running->value => [JobStatus::Succeeded, JobStatus::Failed, JobStatus::Cancelled],
        JobStatus::Succeeded->value => [],
        JobStatus::Failed->value => [],
        JobStatus::Cancelled->value => [],
    ];

    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\Column(length: 120)]
    private string $type;

    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(enumType: JobStatus::class)]
    private JobStatus $status;

    #[ORM\Column(nullable: true)]
    private ?int $progress = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $lockedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lockedAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $lockToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lockExpiresAt = null;

    #[ORM\OneToOne(mappedBy: 'job', targetEntity: JobResult::class, cascade: ['persist'])]
    private ?JobResult $result = null;

    public function __construct(string $type, array $payload)
    {
        $this->id = bin2hex(random_bytes(16));
        $this->type = $type;
        $this->payload = $payload;
        $this->status = JobStatus::Queued;
        $this->progress = 0;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getStatus(): JobStatus
    {
        return $this->status;
    }

    public function getProgress(): ?int
    {
        return $this->progress;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getLockedBy(): ?string
    {
        return $this->lockedBy;
    }

    public function getLockedAt(): ?\DateTimeImmutable
    {
        return $this->lockedAt;
    }

    public function getLockToken(): ?string
    {
        return $this->lockToken;
    }

    public function getLockExpiresAt(): ?\DateTimeImmutable
    {
        return $this->lockExpiresAt;
    }

    public function getResult(): ?JobResult
    {
        return $this->result;
    }

    public function transitionTo(JobStatus $newStatus): void
    {
        $allowed = self::ALLOWED_TRANSITIONS[$this->status->value] ?? [];
        if (!in_array($newStatus, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid job status transition from %s to %s.', $this->status->value, $newStatus->value));
        }

        $this->status = $newStatus;
        $this->touch();
    }

    public function setProgress(?int $progress): void
    {
        if ($progress === null) {
            return;
        }

        $this->progress = max(0, min(100, $progress));
        $this->touch();
    }

    public function lock(string $agentId, string $token, \DateTimeImmutable $expiresAt): void
    {
        if ($this->isLocked(new \DateTimeImmutable())) {
            throw new \InvalidArgumentException('Job is already locked.');
        }

        $this->lockedBy = $agentId;
        $this->lockedAt = new \DateTimeImmutable();
        $this->lockToken = $token;
        $this->lockExpiresAt = $expiresAt;
        $this->touch();
    }

    public function unlock(string $token): void
    {
        if ($this->lockToken === null || $this->lockToken !== $token) {
            throw new \InvalidArgumentException('Invalid lock token.');
        }

        $this->lockedBy = null;
        $this->lockedAt = null;
        $this->lockToken = null;
        $this->lockExpiresAt = null;
        $this->touch();
    }

    public function isLocked(\DateTimeImmutable $now): bool
    {
        if ($this->lockToken === null || $this->lockExpiresAt === null) {
            return false;
        }

        return $this->lockExpiresAt > $now;
    }

    public function attachResult(JobResult $result): void
    {
        $this->result = $result;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
