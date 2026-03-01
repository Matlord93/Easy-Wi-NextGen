<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Mail\Queue;

use App\Message\MailControlPlaneJobMessage;
use App\Module\Core\Domain\Enum\MailJobType;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MailControlPlaneJobEnqueuer
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function enqueue(string $nodeId, MailJobType $type, array $payload, string $correlationId, string $idempotencyKey, int $maxAttempts = 5): MailControlPlaneJobMessage
    {
        $message = new MailControlPlaneJobMessage(
            nodeId: $nodeId,
            type: $type,
            correlationId: $correlationId,
            idempotencyKey: $idempotencyKey,
            payload: $payload,
            maxAttempts: max(1, $maxAttempts),
        );

        $this->bus->dispatch($message);

        return $message;
    }
}
