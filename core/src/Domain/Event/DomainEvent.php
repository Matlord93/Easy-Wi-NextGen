<?php

declare(strict_types=1);

namespace App\Domain\Event;

interface DomainEvent
{
    public function getName(): string;

    public function getPayload(): array;

    public function getOccurredAt(): \DateTimeImmutable;
}
