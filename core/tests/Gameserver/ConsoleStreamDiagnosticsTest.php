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
}
