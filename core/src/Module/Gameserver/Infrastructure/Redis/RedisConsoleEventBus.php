<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Infrastructure\Redis;

use App\Module\Gameserver\Application\Console\ConsoleEventBusInterface;

final class RedisConsoleEventBus implements ConsoleEventBusInterface
{
    public function __construct(private readonly \Redis $redis, private readonly int $bufferSize = 500)
    {
    }

    public function publishConsoleEvent(int $instanceId, array $payload): void
    {
        $payload['seq'] = $this->resolveSequence($instanceId, $payload);
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->redis->multi();
        $this->redis->rPush($this->bufferKey($instanceId), $json);
        $this->redis->lTrim($this->bufferKey($instanceId), -1 * $this->bufferSize, -1);
        $this->redis->publish($this->channel($instanceId), $json);
        $this->redis->exec();
    }

    public function replayConsoleEvents(int $instanceId, int $lastSeq): array
    {
        $events = $this->redis->lRange($this->bufferKey($instanceId), 0, -1);
        if (!is_array($events)) {
            return [];
        }

        $out = [];
        foreach ($events as $raw) {
            if (!is_string($raw)) {
                continue;
            }
            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                continue;
            }
            if ((int) ($payload['seq'] ?? 0) > $lastSeq) {
                $out[] = $payload;
            }
        }

        return $out;
    }

    public function consumeConsoleEvents(int $instanceId, callable $onEvent, callable $shouldStop): void
    {
        $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, 1.0);

        try {
            $this->redis->subscribe([$this->channel($instanceId)], function (\Redis $redis, string $channel, string $message) use ($onEvent, $shouldStop): void {
                if ($shouldStop()) {
                    $redis->unsubscribe([$channel]);

                    return;
                }

                $payload = json_decode($message, true);
                if (is_array($payload)) {
                    $onEvent($payload);
                }
            });
        } catch (\RedisException) {
            // reconnect handled by caller.
        }
    }

    public function incrementSubscriber(int $instanceId): void
    {
        $key = $this->subscriberKey($instanceId);
        $this->redis->incr($key);
        $this->redis->expire($key, 60);
    }

    public function refreshSubscriberTtl(int $instanceId): void
    {
        $this->redis->expire($this->subscriberKey($instanceId), 60);
    }

    public function decrementSubscriber(int $instanceId): void
    {
        $this->redis->decr($this->subscriberKey($instanceId));
    }

    public function getSubscriberCount(int $instanceId): int
    {
        return (int) $this->redis->get($this->subscriberKey($instanceId));
    }

    public function getInstancesWithSubscribers(): array
    {
        $keys = $this->scanSubscriberKeys();
        $out = [];
        foreach ($keys as $key) {
            if (!is_string($key) || !str_contains($key, ':')) {
                continue;
            }
            $id = (int) substr($key, strrpos($key, ':') + 1);
            if ($id > 0 && $this->getSubscriberCount($id) > 0) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }


    private function resolveSequence(int $instanceId, array $payload): int
    {
        $provided = (int) ($payload['seq'] ?? 0);
        if ($provided > 0) {
            return $provided;
        }

        return (int) $this->redis->incr($this->sequenceKey($instanceId));
    }

    /** @return list<string> */
    private function scanSubscriberKeys(): array
    {
        $cursor = null;
        $keys = [];
        do {
            $result = $this->redis->scan($cursor, 'console_subscribers:*', 100);
            if (is_array($result) && $result !== []) {
                foreach ($result as $key) {
                    if (is_string($key)) {
                        $keys[] = $key;
                    }
                }
            }
        } while ($cursor !== 0 && $cursor !== '0');

        return $keys;
    }

    private function channel(int $instanceId): string
    {
        return sprintf('console:%d', $instanceId);
    }

    private function bufferKey(int $instanceId): string
    {
        return sprintf('consolebuf:%d', $instanceId);
    }

    private function subscriberKey(int $instanceId): string
    {
        return sprintf('console_subscribers:%d', $instanceId);
    }

    private function sequenceKey(int $instanceId): string
    {
        return sprintf('console_seq:%d', $instanceId);
    }
}
