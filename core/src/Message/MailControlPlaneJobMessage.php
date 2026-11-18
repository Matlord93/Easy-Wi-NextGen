<?php

declare(strict_types=1);

namespace App\Message;

use App\Module\Core\Domain\Enum\MailJobType;

/**
 * Messenger wire contract retained for external producers and already queued messages.
 * Public fields, constructor arguments and defaults are part of that contract.
 *
 * @api
 */
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
