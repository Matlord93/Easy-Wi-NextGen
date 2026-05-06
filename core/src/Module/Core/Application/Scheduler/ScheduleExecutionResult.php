<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Scheduler;

final class ScheduleExecutionResult
{
    /** @param list<string> $createdJobIds */
    public function __construct(
        public readonly string $status,
        public readonly ?string $message = null,
        public readonly array $createdJobIds = [],
    ) {
    }

    public static function success(?string $message = null, array $createdJobIds = []): self
    {
        return new self('success', $message, $createdJobIds);
    }

    public static function skipped(?string $message = null): self
    {
        return new self('skipped', $message);
    }

    public static function failed(string $message): self
    {
        return new self('failed', $message);
    }
}
