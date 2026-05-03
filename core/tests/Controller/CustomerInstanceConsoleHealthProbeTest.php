<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Gameserver\Infrastructure\Client\AgentGameServerClient;
use App\Module\Gameserver\UI\Controller\Customer\CustomerInstanceActionApiController;
use PHPUnit\Framework\TestCase;

final class CustomerInstanceConsoleHealthProbeTest extends TestCase
{
    public function testSocketEvidenceKeepsCommandChannelActiveWithoutQueryStatus(): void
    {
        $server = $this->createSocket(1);
        $controller = $this->buildController([]);
        $probe = $this->invokeProbe($controller, 1);
        fclose($server);

        self::assertTrue($probe['running']);
        self::assertSame('degraded_query_unavailable', $probe['runtime_status']);
        self::assertSame('unknown', $probe['query_status']);
    }

    public function testSocketEvidenceOverridesOfflineRuntimeProbe(): void
    {
        $server = $this->createSocket(1);
        $controller = $this->buildController(['status' => 'stopped', 'running' => false]);
        $probe = $this->invokeProbe($controller, 1);
        fclose($server);

        self::assertTrue($probe['running']);
        self::assertSame('degraded_query_unavailable', $probe['runtime_status']);
    }

    private function buildController(array $runtimeStatusPayload): CustomerInstanceActionApiController
    {
        $agentClient = $this->createMock(AgentGameServerClient::class);
        if ($runtimeStatusPayload === []) {
            $agentClient->method('getInstanceStatus')->willThrowException(new \RuntimeException('offline'));
        } else {
            $agentClient->method('getInstanceStatus')->willReturn($runtimeStatusPayload);
        }

        $reflection = new \ReflectionClass(CustomerInstanceActionApiController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $property = $reflection->getProperty('agentGameServerClient');
        $property->setAccessible(true);
        $property->setValue($controller, $agentClient);

        return $controller;
    }

    private function invokeProbe(CustomerInstanceActionApiController $controller, int $instanceId): array
    {
        $instance = $this->createMock(Instance::class);
        $instance->method('getId')->willReturn($instanceId);
        $instance->method('getQueryStatusCache')->willReturn([]);

        $method = new \ReflectionMethod($controller, 'resolveConsoleRuntimeProbe');
        $method->setAccessible(true);

        return $method->invoke($controller, $instance);
    }

    private function createSocket(int $instanceId)
    {
        $dir = sprintf('/run/easywi/instances/%d', $instanceId);
        @mkdir($dir, 0777, true);
        $path = $dir . '/console.sock';
        
        $handle = @fopen($path, 'wb');
        self::assertNotFalse($handle, sprintf('Failed creating socket marker file at %s', $path));

        return $handle;

    }
}
