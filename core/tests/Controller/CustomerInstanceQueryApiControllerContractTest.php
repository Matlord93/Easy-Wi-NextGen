<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class CustomerInstanceQueryApiControllerContractTest extends TestCase
{
    public function testControllerUsesEnvelopeAndExpectedErrorCodes(): void
    {
        $controller = (string) file_get_contents(__DIR__.'/../../src/Module/Gameserver/UI/Controller/Customer/CustomerInstanceQueryApiController.php');

        self::assertStringContainsString('/api/instances/{id}/query', $controller);
        self::assertStringContainsString('/api/instances/{id}/query/health', $controller);
        self::assertStringContainsString("'ok' => true", $controller);
        self::assertStringContainsString("'ok' => false", $controller);
        self::assertStringContainsString("'resolved_host'", $controller);
        self::assertStringContainsString("'resolved_host_source'", $controller);
        self::assertStringContainsString("'timeout_ms'", $controller);
        self::assertStringContainsString("'network_mode'", $controller);
        self::assertStringContainsString("'resolved_port'", $controller);
        self::assertStringContainsString("'port_source'", $controller);
        self::assertStringContainsString("'last_query_at'", $controller);
        self::assertStringContainsString("'last_error_code'", $controller);
        self::assertStringContainsString("'INVALID_QUERY_CONFIG'", $controller);
        self::assertStringContainsString("'INVALID_INSTANCE_HOST'", $controller);
        self::assertStringContainsString("'QUERY_TIMEOUT'", $controller);
        self::assertStringContainsString("'CONNECTION_REFUSED'", $controller);
        self::assertStringContainsString("'INSTANCE_OFFLINE'", $controller);
        self::assertStringContainsString("'FORBIDDEN'", $controller);
    }
}
