<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Scheduler;

final class InternalSchedule
{
    public function __construct(
        public readonly string $source,
        public readonly string $id,
        public readonly string $name,
        public readonly string $type,
        public readonly string $module,
        public readonly string $cronExpression,
        public readonly bool $enabled,
        public readonly array $payload = [],
        public readonly ?\DateTimeImmutable $lastRunAt = null,
        public readonly ?\DateTimeImmutable $lastQueuedAt = null,
        public readonly ?\DateTimeImmutable $nextRunAt = null,
        public readonly ?string $lastStatus = null,
        public readonly ?string $lastError = null,
        public readonly ?string $lastJobId = null,
        public readonly ?\DateTimeImmutable $lockedUntil = null,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?\DateTimeImmutable $updatedAt = null,
    ) {
    }

    public function key(): string
    {
        return $this->source.':'.$this->id;
    }
}
