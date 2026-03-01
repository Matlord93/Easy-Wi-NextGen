<?php

declare(strict_types=1);

namespace App\Module\Core\Dto\Mail\Job;

final readonly class MailRestartServiceJobDto
{
    public function __construct(
        public string $nodeId,
        public string $service,
    ) {
    }

    /** @return array<string,mixed> */
    public function toPayload(): array
    {
        return [
            'node_id' => $this->nodeId,
            'service' => strtolower(trim($this->service)),
        ];
    }
}
