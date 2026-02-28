<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Infrastructure\Mercure;

use App\Module\Gameserver\Application\Console\ConsoleRealtimePublisherInterface;

final readonly class ConsoleMercurePublisher
{
    public function __construct(private ConsoleRealtimePublisherInterface $publisher)
    {
    }

    public function publishConsole(int $instanceId, array $payload): void
    {
        $topic = sprintf('https://easy-wi.com/instances/%d/console', $instanceId);
        $this->publisher->publish($topic, $payload, true);
    }

    public function publishStats(int $instanceId, array $payload): void
    {
        $topic = sprintf('https://easy-wi.com/instances/%d/stats', $instanceId);
        $this->publisher->publish($topic, $payload, true);
    }
}
