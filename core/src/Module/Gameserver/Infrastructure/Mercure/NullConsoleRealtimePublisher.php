<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Infrastructure\Mercure;

use App\Module\Gameserver\Application\Console\ConsoleRealtimePublisherInterface;

final class NullConsoleRealtimePublisher implements ConsoleRealtimePublisherInterface
{
    public function publish(string $topic, array $payload, bool $isPrivate = true): void
    {
    }
}
