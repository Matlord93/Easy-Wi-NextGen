<?php

declare(strict_types=1);

namespace App\Module\HostingPanel\Application\Job;

class DispatchJobMessage
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public readonly int $nodeId,
        public readonly string $type,
        public readonly string $idempotencyKey,
        public readonly array $payload,
    ) {
    }
}
