<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Console;

interface ConsoleEventBusInterface
{
    public function publishConsoleEvent(int $instanceId, array $payload): void;

    /** @return list<array<string,mixed>> */
    public function replayConsoleEvents(int $instanceId, int $lastSeq): array;

    /**
     * @param callable(array<string,mixed>):void $onEvent
     */
    public function consumeConsoleEvents(int $instanceId, callable $onEvent, callable $shouldStop): void;

    public function incrementSubscriber(int $instanceId): void;

    public function refreshSubscriberTtl(int $instanceId): void;

    public function decrementSubscriber(int $instanceId): void;

    public function getSubscriberCount(int $instanceId): int;

    /** @return list<int> */
    public function getInstancesWithSubscribers(): array;
}
