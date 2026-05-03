<?php

declare(strict_types=1);

namespace App\Tests\Gameserver;

use PHPUnit\Framework\TestCase;

final class ConsoleServicesWiringTest extends TestCase
{
    public function testProdServicesUseGrpcClientAndRedisClassDefinition(): void
    {
        $services = file_get_contents(__DIR__ . '/../../config/services.yaml');
        self::assertIsString($services);
        self::assertStringContainsString("ConsoleAgentGrpcClientInterface: '@App\\Module\\Gameserver\\Infrastructure\\Grpc\\GrpcConsoleAgentGrpcClient'", $services);
        self::assertStringContainsString("Redis:\n    class: Redis", $services);
    }

    public function testDevServicesDoNotForceNullConsoleClient(): void
    {
        $services = file_get_contents(__DIR__ . '/../../config/services_dev.yaml');
        self::assertIsString($services);
        self::assertStringContainsString("ConsoleAgentGrpcClientInterface: '@App\\Module\\Gameserver\\Infrastructure\\Grpc\\GrpcConsoleAgentGrpcClient'", $services);
        self::assertStringNotContainsString('NullConsoleAgentGrpcClient', $services);
    }
}
