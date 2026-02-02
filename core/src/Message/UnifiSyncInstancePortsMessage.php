<?php

declare(strict_types=1);

namespace App\Message;

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
