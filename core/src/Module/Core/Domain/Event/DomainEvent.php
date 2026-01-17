<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Event;

interface DomainEvent
{
    public function getName(): string;

    public function getPayload(): array;

    public function getOccurredAt(): \DateTimeImmutable;
}
