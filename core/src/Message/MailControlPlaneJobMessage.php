<?php

declare(strict_types=1);

namespace App\Message;

use App\Module\Core\Domain\Enum\MailJobType;

final readonly class MailControlPlaneJobMessage
{
    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(
        public string $nodeId,
        public MailJobType $type,
        public string $correlationId,
        public string $idempotencyKey,
        public array $payload,
        public int $maxAttempts = 5,
    ) {
    }
}
