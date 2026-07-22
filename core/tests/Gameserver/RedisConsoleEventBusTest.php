<?php

declare(strict_types=1);

namespace App\Tests\Gameserver;

use App\Module\Gameserver\Infrastructure\Redis\RedisConsoleEventBus;
use PHPUnit\Framework\TestCase;

final class RedisConsoleEventBusTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('Redis extension not available in test runtime.');
        }
    }

    public function testPublishedConsoleBufferExpiresAfterReconnectWindow(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())->method('rPush')->with('consolebuf:42', self::isType('string'));
        $redis->expects(self::once())->method('lTrim')->with('consolebuf:42', -500, -1);
        $redis->expects(self::once())->method('expire')->with('consolebuf:42', 900);
        $redis->expects(self::once())->method('publish')->with('console:42', self::isType('string'));

        $bus = new RedisConsoleEventBus($redis);
        $bus->publishConsoleEvent(42, ['seq' => 1, 'message' => 'hello']);
    }

    public function testGetInstancesWithSubscribersFindsInstancesViaTtlKeys(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('keys')->with('console_subscribers:*')->willReturn(['console_subscribers:2']);
        $redis->method('get')->with('console_subscribers:2')->willReturn('1');
        $redis->method('rawCommand')->willReturn([]);

        $bus = new RedisConsoleEventBus($redis);

        self::assertSame([2], $bus->getInstancesWithSubscribers());
    }

    public function testGetInstancesWithSubscribersFindsInstancesViaPubSubChannels(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('keys')->with('console_subscribers:*')->willReturn([]);
        $redis->method('get')->with('console_subscribers:2')->willReturn(false);
        $redis->expects(self::once())->method('setex')->with('console_subscribers:2', 60, '1');
        $redis->method('rawCommand')->willReturnCallback(static function (string ...$args): array {
            if ($args === ['PUBSUB', 'CHANNELS', 'console:*']) {
                return ['console:2'];
            }
            if ($args === ['PUBSUB', 'NUMSUB', 'console:2']) {
                return ['console:2', 1];
            }

            return [];
        });

        $bus = new RedisConsoleEventBus($redis);

        self::assertSame([2], $bus->getInstancesWithSubscribers());
    }

    public function testGetInstancesWithSubscribersDeduplicatesTtlAndPubSubInstances(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('keys')->with('console_subscribers:*')->willReturn(['console_subscribers:2']);
        $redis->method('get')->with('console_subscribers:2')->willReturn('1');
        $redis->expects(self::never())->method('setex');
        $redis->method('rawCommand')->willReturnCallback(static function (string ...$args): array {
            if ($args === ['PUBSUB', 'CHANNELS', 'console:*']) {
                return ['console:2'];
            }
            if ($args === ['PUBSUB', 'NUMSUB', 'console:2']) {
                return ['console:2', 1];
            }

            return [];
        });

        $bus = new RedisConsoleEventBus($redis);

        self::assertSame([2], $bus->getInstancesWithSubscribers());
    }

    public function testGetInstancesWithSubscribersFallsBackToTtlKeysWhenPubSubChannelsFails(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('keys')->with('console_subscribers:*')->willReturn(['console_subscribers:3']);
        $redis->method('get')->with('console_subscribers:3')->willReturn('1');
        $redis->method('rawCommand')->willThrowException(new \RedisException('pubsub unavailable'));

        $bus = new RedisConsoleEventBus($redis);

        self::assertSame([3], $bus->getInstancesWithSubscribers());
    }
}
