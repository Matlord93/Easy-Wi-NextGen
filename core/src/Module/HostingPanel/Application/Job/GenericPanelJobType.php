<?php

declare(strict_types=1);

namespace App\Module\HostingPanel\Application\Job;

final class GenericPanelJobType implements PanelJobTypeInterface
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly string $jobType,
        private readonly array $jobPayload = [],
    ) {
    }

    public function type(): string
    {
        return $this->jobType;
    }

    public function payload(): array
    {
        return $this->jobPayload;
    }
}
