<?php

declare(strict_types=1);

namespace App\Module\Core\Dto\Mail\Job;

final readonly class MailCheckDnsJobDto
{
    /** @param list<string> $expectedMx */
    public function __construct(
        public string $nodeId,
        public string $domain,
        public string $dkimSelector,
        public array $expectedMx = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toPayload(): array
    {
        return [
            'node_id' => $this->nodeId,
            'domain' => strtolower(trim($this->domain)),
            'dkim_selector' => trim($this->dkimSelector),
            'expected_mx' => array_values(array_unique(array_map(static fn (string $v): string => strtolower(trim($v)), $this->expectedMx))),
        ];
    }
}
