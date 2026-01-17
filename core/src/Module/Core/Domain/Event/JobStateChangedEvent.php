<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Event;

final class JobStateChangedEvent implements DomainEvent
{
    private readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        private readonly string $jobId,
        private readonly string $jobType,
        private readonly string $previousStatus,
        private readonly string $currentStatus,
    ) {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getName(): string
    {
        return 'job.state_changed';
    }

    public function getPayload(): array
    {
        return [
            'job_id' => $this->jobId,
            'job_type' => $this->jobType,
            'previous_status' => $this->previousStatus,
            'current_status' => $this->currentStatus,
            'occurred_at' => $this->occurredAt->format(DATE_RFC3339),
        ];
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
