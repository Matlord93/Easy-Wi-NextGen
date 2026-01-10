<?php

declare(strict_types=1);

namespace App\Message;

final class InstanceActionMessage
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private readonly string $action,
        private readonly int $actorId,
        private readonly int $instanceId,
        private readonly array $payload,
    ) {
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getActorId(): int
    {
        return $this->actorId;
    }

    public function getInstanceId(): int
    {
        return $this->instanceId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }
}
