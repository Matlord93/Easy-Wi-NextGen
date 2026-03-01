<?php

declare(strict_types=1);

namespace App\Module\Core\Dto\Mail\Job;

final readonly class MailFlushQueueJobDto
{
    public function __construct(
        public string $nodeId,
        public ?string $reason = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toPayload(): array
    {
        return [
            'node_id' => $this->nodeId,
            'reason' => $this->reason !== null ? trim($this->reason) : null,
        ];
    }
}
