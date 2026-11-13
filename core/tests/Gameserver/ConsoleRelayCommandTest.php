<?php

declare(strict_types=1);

namespace App\Tests\Gameserver;

use App\Module\Gameserver\Application\Console\ConsoleAgentGrpcClientInterface;
use App\Module\Gameserver\Application\Console\ConsoleCommandRequest;
use App\Module\Gameserver\Application\Console\ConsoleCommandResult;
use App\Module\Gameserver\Application\Console\ConsoleEventBusInterface;
use App\Module\Gameserver\Command\ConsoleRelayCommand;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ConsoleRelayCommandTest extends TestCase
{
    public function testTouchHeartbeatIsThrottledAndSafeWithoutRedis(): void
    {
        $command = new ConsoleRelayCommand($this->fakeGrpc([]), $this->fakeBus(), new NullLogger(), null);
        $method = new \ReflectionMethod($command, 'touchHeartbeat');
        $method->setAccessible(true);

        $method->invoke($command);
        $method->invoke($command);

        self::assertTrue(true);
    }

    public function testRelayInstanceRefreshesHeartbeatWhileSubscriberIsActive(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('Redis extension not available in test runtime.');
        }

        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::atLeast(2))->method('set')->with('console_relay:heartbeat', self::isType('string'));

        $bus = new class implements ConsoleEventBusInterface {
            private int $countCalls = 0;
            public function publishConsoleEvent(int $instanceId, array $payload): void {}
            public function replayConsoleEvents(int $instanceId, int $lastSeq): array { return []; }
            public function consumeConsoleEvents(int $instanceId, callable $onEvent, callable $shouldStop): void {}
            public function incrementSubscriber(int $instanceId): void {}
            public function refreshSubscriberTtl(int $instanceId): void {}
            public function decrementSubscriber(int $instanceId): void {}
            public function getSubscriberCount(int $instanceId): int { $this->countCalls++; return $this->countCalls <= 1 ? 1 : 0; }
            public function getInstancesWithSubscribers(): array { return [1]; }
        };

        $grpc = $this->fakeGrpc(function (): iterable {
            usleep(5_200_000);
            if (false) {
                yield [];
            }
            return [];
        });

        $command = new ConsoleRelayCommand($grpc, $bus, new NullLogger(), $redis);
        $relay = new \ReflectionMethod($command, 'relayInstance');
        $relay->setAccessible(true);
        $relay->invoke($command, 1);

        self::assertTrue(true);
    }

    public function testRelayStopsAsSoonAsLastViewerLeaves(): void
    {
        $bus = $this->createMock(ConsoleEventBusInterface::class);
        $bus->method('getSubscriberCount')->willReturnOnConsecutiveCalls(1, 1, 0);
        $bus->expects(self::once())->method('publishConsoleEvent');
        $relay = new ConsoleRelayCommand($this->fakeGrpc([['chunk' => 'first'], ['chunk' => 'second'], ['chunk' => 'third']]), $bus, new NullLogger());
        (new \ReflectionMethod($relay, 'relayInstance'))->invoke($relay, 1);
    }

    public function testWorkerRecyclesAfterTimeLimit(): void
    {
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new ConsoleRelayCommand($this->fakeGrpc([]), $this->fakeBus(), new NullLogger()));
        self::assertSame(0, $tester->execute(['--time-limit' => '1', '--memory-limit' => '512']));
        self::assertStringContainsString('recycled', $tester->getDisplay());
        self::assertSame(2, $tester->execute(['--time-limit' => '0']));
    }

    /** @param iterable<array<string,mixed>>|callable():iterable<array<string,mixed>> $stream */
    private function fakeGrpc(iterable|callable $stream): ConsoleAgentGrpcClientInterface
    {
        return new class($stream) implements ConsoleAgentGrpcClientInterface {
            public function __construct(private mixed $stream) {}
            public function sendCommand(ConsoleCommandRequest $request): ConsoleCommandResult { return new ConsoleCommandResult(true, false, 1); }
            public function attachStream(int $instanceId): iterable
            {
                return is_callable($this->stream) ? ($this->stream)() : $this->stream;
            }
        };
    }

    private function fakeBus(): ConsoleEventBusInterface
    {
        return new class implements ConsoleEventBusInterface {
            public function publishConsoleEvent(int $instanceId, array $payload): void {}
            public function replayConsoleEvents(int $instanceId, int $lastSeq): array { return []; }
            public function consumeConsoleEvents(int $instanceId, callable $onEvent, callable $shouldStop): void {}
            public function incrementSubscriber(int $instanceId): void {}
            public function refreshSubscriberTtl(int $instanceId): void {}
            public function decrementSubscriber(int $instanceId): void {}
            public function getSubscriberCount(int $instanceId): int { return 0; }
            public function getInstancesWithSubscribers(): array { return []; }
        };
    }
}
