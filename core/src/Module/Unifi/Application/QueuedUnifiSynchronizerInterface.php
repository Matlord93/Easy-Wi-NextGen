<?php

declare(strict_types=1);

namespace App\Module\Unifi\Application;

interface QueuedUnifiSynchronizerInterface
{
    /**
     * @param callable():void $heartbeat
     * @return array{status:string,...}
     */
    public function syncQueued(int $instanceId, callable $heartbeat): array;
}
