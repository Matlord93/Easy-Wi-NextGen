<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Event;

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

    #[\Override]
    public function getName(): string
    {
        return $this->name;
    }

    /** @return array{resource_type: string, resource_id: string, occurred_at: string} */
    #[\Override]
    public function getPayload(): array
    {
        return [
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'occurred_at' => $this->occurredAt->format(DATE_RFC3339),
        ];
    }

    #[\Override]
    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
