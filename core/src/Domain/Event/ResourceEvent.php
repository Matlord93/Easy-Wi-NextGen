<?php

declare(strict_types=1);

namespace App\Domain\Event;

final class ResourceEvent implements DomainEvent
{
    private readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        private readonly string $name,
        private readonly string $resourceType,
        private readonly string $resourceId,
    ) {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPayload(): array
    {
        return [
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'occurred_at' => $this->occurredAt->format(DATE_RFC3339),
        ];
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
