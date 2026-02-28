<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Console;

interface ConsoleRealtimePublisherInterface
{
    public function publish(string $topic, array $payload, bool $isPrivate = true): void;
}
