<?php

declare(strict_types=1);

namespace App\Module\Core\Dto\Mail\Job;

final readonly class MailRotateDkimJobDto
{
    public function __construct(
        public string $nodeId,
        public string $domain,
        public string $selector,
        public bool $activateImmediately = false,
    ) {
    }

    /** @return array<string,mixed> */
    public function toPayload(): array
    {
        return [
            'node_id' => $this->nodeId,
            'domain' => strtolower(trim($this->domain)),
            'selector' => trim($this->selector),
            'activate_immediately' => $this->activateImmediately,
        ];
    }
}
