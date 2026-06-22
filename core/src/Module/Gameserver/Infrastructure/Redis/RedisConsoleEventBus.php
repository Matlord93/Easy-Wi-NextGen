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
        $this->refreshSubscriberTtl($instanceId);
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
            // After a subscribe timeout the socket may still be in pub/sub mode.
            // Closing it ensures phpredis reconnects cleanly on the next regular
            // command (e.g. EXPIRE in refreshSubscriberTtl) instead of sending
            // that command over a connection that is in subscriber mode.
            try {
                $this->redis->close();
            } catch (\Throwable) {
                // Ignore close errors; reconnect will happen on the next command.
            }
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
        $tracked = (int) $this->redis->get($this->subscriberKey($instanceId));
        $pubSub = $this->getPubSubSubscriberCount($instanceId);

        if ($pubSub > 0 && $tracked <= 0) {
            $this->repairSubscriberKey($instanceId);
        }

        return max($tracked, $pubSub);
    }

    public function getInstancesWithSubscribers(): array
    {
        $out = [];
        $keys = $this->redis->keys('console_subscribers:*');
        if (is_array($keys)) {
            foreach ($keys as $key) {
                if (!is_string($key) || !str_contains($key, ':')) {
                    continue;
                }
                $id = (int) substr($key, strrpos($key, ':') + 1);
                if ($id > 0 && (int) $this->redis->get($this->subscriberKey($id)) > 0) {
                    $out[] = $id;
                }
            }
        }

        foreach ($this->getInstancesWithPubSubSubscribers() as $id) {
            $out[] = $id;
            if ((int) $this->redis->get($this->subscriberKey($id)) <= 0) {
                $this->repairSubscriberKey($id);
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<int> */
    private function getInstancesWithPubSubSubscribers(): array
    {
        try {
            $channels = $this->redis->rawCommand('PUBSUB', 'CHANNELS', 'console:*');
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($channels)) {
            return [];
        }

        $out = [];
        foreach ($channels as $channel) {
            if (!is_string($channel) || !preg_match('/^console:(\d+)$/', $channel, $matches)) {
                continue;
            }
            $id = (int) $matches[1];
            if ($id > 0 && $this->getPubSubSubscriberCount($id) > 0) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    private function getPubSubSubscriberCount(int $instanceId): int
    {
        try {
            $result = $this->redis->rawCommand('PUBSUB', 'NUMSUB', $this->channel($instanceId));
        } catch (\Throwable) {
            return 0;
        }

        if (!is_array($result)) {
            return 0;
        }

        if (array_key_exists($this->channel($instanceId), $result)) {
            return (int) $result[$this->channel($instanceId)];
        }

        for ($i = 0, $count = count($result); $i < $count - 1; $i += 2) {
            if ($result[$i] === $this->channel($instanceId)) {
                return (int) $result[$i + 1];
            }
        }

        return 0;
    }

    private function repairSubscriberKey(int $instanceId): void
    {
        try {
            $this->redis->setex($this->subscriberKey($instanceId), 60, '1');
        } catch (\Throwable) {
            // Pub/Sub discovery must remain a best-effort enhancement; the
            // legacy TTL-key mechanism continues to work if repair fails.
        }
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
}
