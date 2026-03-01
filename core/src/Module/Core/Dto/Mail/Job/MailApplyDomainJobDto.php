<?php

declare(strict_types=1);

namespace App\Module\Core\Dto\Mail\Job;

final readonly class MailApplyDomainJobDto
{
    public function __construct(
        public string $nodeId,
        public string $domain,
        public string $snapshotId,
        public bool $dryRun = false,
    ) {
    }

    /** @return array<string,mixed> */
    public function toPayload(): array
    {
        return [
            'node_id' => $this->nodeId,
            'domain' => strtolower(trim($this->domain)),
            'snapshot_id' => trim($this->snapshotId),
            'dry_run' => $this->dryRun,
        ];
    }
}
