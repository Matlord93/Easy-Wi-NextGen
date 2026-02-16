<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class CustomerInstanceQueryApiControllerContractTest extends TestCase
{
    public function testControllerUsesEnvelopeAndExpectedErrorCodes(): void
    {
        $controller = (string) file_get_contents(__DIR__.'/../../src/Module/Gameserver/UI/Controller/Customer/CustomerInstanceQueryApiController.php');

        self::assertStringContainsString("/api/instances/{id}/query", $controller);
        self::assertStringContainsString("'ok' => true", $controller);
        self::assertStringContainsString("'ok' => false", $controller);
        self::assertStringContainsString("'INVALID_QUERY_CONFIG'", $controller);
        self::assertStringContainsString("'QUERY_TIMEOUT'", $controller);
        self::assertStringContainsString("'QUERY_UNREACHABLE'", $controller);
        self::assertStringContainsString("'INSTANCE_OFFLINE'", $controller);
        self::assertStringContainsString("'FORBIDDEN'", $controller);
    }
}
