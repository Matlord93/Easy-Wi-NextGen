<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Gameserver\Infrastructure\Client\AgentGameServerClient;
use App\Module\Gameserver\UI\Controller\Customer\CustomerInstanceActionApiController;
use PHPUnit\Framework\TestCase;

final class CustomerInstanceConsoleHealthProbeTest extends TestCase
{
    private string $runtimeDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runtimeDir = sys_get_temp_dir() . '/easywi-runtime-' . bin2hex(random_bytes(8));
        putenv('EASYWI_RUNTIME_DIR=' . $this->runtimeDir);
    }

    protected function tearDown(): void
    {
        putenv('EASYWI_RUNTIME_DIR');
        $socketPath = sprintf('%s/instances/%d/console.sock', $this->runtimeDir, 1);
        if (is_file($socketPath)) {
            unlink($socketPath);
        }
        foreach ([sprintf('%s/instances/%d', $this->runtimeDir, 1), $this->runtimeDir.'/instances', $this->runtimeDir] as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }

        parent::tearDown();
    }
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


    public function testEnvelopeRuntimeProbeIsRecognized(): void
    {
        $controller = $this->buildController(['ok' => true, 'data' => ['status' => 'running', 'running' => true]]);
        $probe = $this->invokeProbe($controller, 1);

        self::assertTrue($probe['running']);
        self::assertSame('running', $probe['runtime_status']);
    }

    public function testAlternativeRuntimeStatusFieldIsRecognized(): void
    {
        $controller = $this->buildController(['ok' => true, 'data' => ['runtime_status' => 'online']]);
        $probe = $this->invokeProbe($controller, 1);

        self::assertTrue($probe['running']);
        self::assertSame('running', $probe['runtime_status']);
    }

    private function buildController(array $runtimeStatusPayload): CustomerInstanceActionApiController
    {
        $agentClient = $this->createStub(AgentGameServerClient::class);
        if ($runtimeStatusPayload === []) {
            $agentClient->method('getInstanceStatus')->willThrowException(new \RuntimeException('offline'));
        } else {
            $agentClient->method('getInstanceStatus')->willReturn($runtimeStatusPayload);
        }

        $reflection = new \ReflectionClass(CustomerInstanceActionApiController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $property = $reflection->getProperty('agentGameServerClient');
        $property->setValue($controller, $agentClient);

        return $controller;
    }

    private function invokeProbe(CustomerInstanceActionApiController $controller, int $instanceId): array
    {
        $instance = $this->createStub(Instance::class);
        $instance->method('getId')->willReturn($instanceId);
        $instance->method('getQueryStatusCache')->willReturn([]);

        $method = new \ReflectionMethod($controller, 'resolveConsoleRuntimeProbe');

        return $method->invoke($controller, $instance);
    }

    private function createSocket(int $instanceId)
    {
        $dir = sprintf('%s/instances/%d', $this->runtimeDir, $instanceId);
        @mkdir($dir, 0777, true);
        $path = $dir . '/console.sock';

        $handle = @fopen($path, 'wb');
        self::assertNotFalse($handle, sprintf('Failed creating socket marker file at %s', $path));

        return $handle;
    }
}
