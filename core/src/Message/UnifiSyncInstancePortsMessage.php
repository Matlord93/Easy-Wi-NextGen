<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Legacy Messenger wire contract for external producers and already queued sync work.
 * New panel requests use UnifiSyncOutbox; the existing consumer remains compatible.
 *
 * @api
 */
final class UnifiSyncInstancePortsMessage
{
    public function __construct(private readonly int $instanceId)
    {
    }

    public function getInstanceId(): int
    {
        return $this->instanceId;
    }
}
