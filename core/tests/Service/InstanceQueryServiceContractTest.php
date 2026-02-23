<?php

declare(strict_types=1);

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;

final class InstanceQueryServiceContractTest extends TestCase
{
    public function testQueuePayloadContainsCompatibilityAndDedicatedPortFields(): void
    {
        $service = (string) file_get_contents(__DIR__.'/../../src/Module/Gameserver/Application/InstanceQueryService.php');

        self::assertStringContainsString("'host' => \$context->getHost()", $service);
        self::assertStringContainsString("'ip' => \$context->getHost()", $service);
        self::assertStringContainsString("'port' => \$context->getGamePort()", $service);
        self::assertStringContainsString("'game_port' => \$context->getGamePort()", $service);
        self::assertStringContainsString("'query_port' => \$context->getQueryPort()", $service);
        self::assertStringContainsString("'fallback_query_ports' => \$fallbackPorts", $service);
    }

    public function testServiceResolvesGamePortIndependentlyFromQueryPort(): void
    {
        $service = (string) file_get_contents(__DIR__.'/../../src/Module/Gameserver/Application/InstanceQueryService.php');

        self::assertStringContainsString('resolveGamePort($instance, $portBlock, $queryPort)', $service);
        self::assertStringNotContainsString('$gamePort = $queryPort;', $service);
        self::assertStringContainsString('$setupVars[\'GAME_PORT\']', $service);
        self::assertStringContainsString("['PORT', 'SERVER_PORT']", $service);
    }

    public function testLegacyPortFallbackIsCheckedBeforeAssignedPort(): void
    {
        $service = (string) file_get_contents(__DIR__.'/../../src/Module/Gameserver/Application/InstanceQueryService.php');

        $legacyPos = strpos($service, "foreach (['PORT', 'SERVER_PORT'] as \$setupPortKey)");
        $assignedPos = strpos($service, 'if ($instance->getAssignedPort() !== null)');

        self::assertNotFalse($legacyPos);
        self::assertNotFalse($assignedPos);
        self::assertLessThan($assignedPos, $legacyPos);
    }
}
