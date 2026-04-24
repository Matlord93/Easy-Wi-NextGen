<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Infrastructure\Console;

use App\Module\Gameserver\Application\Console\ConsoleAgentGrpcClientInterface;
use App\Module\Gameserver\Application\Console\ConsoleEventBusInterface;
use App\Module\Gameserver\Infrastructure\Redis\RedisConsoleEventBus;

final class FallbackConsoleEventBus implements ConsoleEventBusInterface
{
    /** @var array<int,int> */
    private array $subscriberCounts = [];

    /** @var array<int,int> */
    private array $sequenceByInstance = [];

    private ?RedisConsoleEventBus $redisBus = null;

    public function __construct(
        private readonly ConsoleAgentGrpcClientInterface $grpcClient,
        ?\Redis $redis = null,
        int $bufferSize = 500,
    ) {
        if ($redis instanceof \Redis) {
            try {
                $pong = $redis->ping();
                if ($pong === true || strtoupper((string) $pong) === 'PONG') {
                    $this->redisBus = new RedisConsoleEventBus($redis, $bufferSize);
                }
            } catch (\Throwable) {
                $this->redisBus = null;
            }
        }
    }

    public function publishConsoleEvent(int $instanceId, array $payload): void
    {
        if ($this->redisBus instanceof RedisConsoleEventBus) {
            $this->redisBus->publishConsoleEvent($instanceId, $payload);
        }
    }

    public function replayConsoleEvents(int $instanceId, int $lastSeq): array
    {
        if ($this->redisBus instanceof RedisConsoleEventBus) {
            return $this->redisBus->replayConsoleEvents($instanceId, $lastSeq);
        }

        return [];
    }

    public function consumeConsoleEvents(int $instanceId, callable $onEvent, callable $shouldStop): void
    {
        if ($this->redisBus instanceof RedisConsoleEventBus) {
            $this->redisBus->consumeConsoleEvents($instanceId, $onEvent, $shouldStop);
            return;
        }

        foreach ($this->grpcClient->attachStream($instanceId) as $event) {
            if ($shouldStop()) {
                return;
            }

            if (!is_array($event)) {
                continue;
            }

            if (!array_key_exists('seq', $event) || !is_int($event['seq'])) {
                $event['seq'] = $this->nextSequence($instanceId);
            }

            $onEvent($event);
        }
    }

    public function incrementSubscriber(int $instanceId): void
    {
        if ($this->redisBus instanceof RedisConsoleEventBus) {
            $this->redisBus->incrementSubscriber($instanceId);
            return;
        }

        $this->subscriberCounts[$instanceId] = ($this->subscriberCounts[$instanceId] ?? 0) + 1;
    }

    public function refreshSubscriberTtl(int $instanceId): void
    {
        if ($this->redisBus instanceof RedisConsoleEventBus) {
            $this->redisBus->refreshSubscriberTtl($instanceId);
        }
    }

    public function decrementSubscriber(int $instanceId): void
    {
        if ($this->redisBus instanceof RedisConsoleEventBus) {
            $this->redisBus->decrementSubscriber($instanceId);
            return;
        }

        $current = $this->subscriberCounts[$instanceId] ?? 0;
        if ($current <= 1) {
            unset($this->subscriberCounts[$instanceId]);
            return;
        }

        $this->subscriberCounts[$instanceId] = $current - 1;
    }

    public function getSubscriberCount(int $instanceId): int
    {
        if ($this->redisBus instanceof RedisConsoleEventBus) {
            return $this->redisBus->getSubscriberCount($instanceId);
        }

        return $this->subscriberCounts[$instanceId] ?? 0;
    }

    public function getInstancesWithSubscribers(): array
    {
        if ($this->redisBus instanceof RedisConsoleEventBus) {
            return $this->redisBus->getInstancesWithSubscribers();
        }

        return array_map('intval', array_keys($this->subscriberCounts));
    }

    private function nextSequence(int $instanceId): int
    {
        $next = ($this->sequenceByInstance[$instanceId] ?? 0) + 1;
        $this->sequenceByInstance[$instanceId] = $next;

        return $next;
    }
}
