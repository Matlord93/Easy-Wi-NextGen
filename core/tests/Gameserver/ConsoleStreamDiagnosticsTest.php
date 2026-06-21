<?php

declare(strict_types=1);

namespace App\Tests\Gameserver;

use App\Module\Gameserver\Application\Console\AgentEndpointProbeInterface;
use App\Module\Gameserver\Application\Console\ConsoleStreamDiagnostics;
use App\Module\Gameserver\Infrastructure\Mercure\NullConsoleAgentGrpcClient;
use PHPUnit\Framework\TestCase;

final class ConsoleStreamDiagnosticsTest extends TestCase
{
    public function testSnapshotMarksRelayStaleWhenHeartbeatMissing(): void
    {
        $probe = $this->createMock(AgentEndpointProbeInterface::class);
        $probe->method('hasAnyEndpoint')->willReturn(false);

        $diagnostics = new ConsoleStreamDiagnostics(new NullConsoleAgentGrpcClient(), $probe, null);
        $snapshot = $diagnostics->snapshot();

        self::assertTrue($snapshot['relay_stale']);
        self::assertFalse($snapshot['backend_configured']);
        self::assertFalse($snapshot['sample_node_endpoint_present']);
    }

    public function testIsRelayRequiredReturnsFalseWithoutRedis(): void
    {
        $probe = $this->createMock(AgentEndpointProbeInterface::class);

        $diagnostics = new ConsoleStreamDiagnostics(new NullConsoleAgentGrpcClient(), $probe, null);

        self::assertFalse($diagnostics->isRelayRequired());
    }

    public function testIsRelayRequiredReturnsFalseWhenRedisPingFails(): void
    {
        // Simulates RedisConnectionFactory returning a non-connected \Redis instance
        // (it catches connection exceptions and returns the object anyway).
        $redis = $this->createMock(\Redis::class);
        $redis->method('ping')->willThrowException(new \RedisException('Connection refused'));

        $probe = $this->createMock(AgentEndpointProbeInterface::class);

        $diagnostics = new ConsoleStreamDiagnostics(new NullConsoleAgentGrpcClient(), $probe, $redis);

        // Even though a \Redis instance was injected, the relay is not required
        // when Redis is actually unreachable — FallbackConsoleEventBus will use
        // direct agent polling in that case.
        self::assertFalse($diagnostics->isRelayRequired());
    }

    public function testIsRelayRequiredReturnsTrueWhenRedisResponds(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('ping')->willReturn(true);

        $probe = $this->createMock(AgentEndpointProbeInterface::class);

        $diagnostics = new ConsoleStreamDiagnostics(new NullConsoleAgentGrpcClient(), $probe, $redis);

        self::assertTrue($diagnostics->isRelayRequired());
    }
}
