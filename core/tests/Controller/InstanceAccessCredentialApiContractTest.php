<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class InstanceAccessCredentialApiContractTest extends TestCase
{
    public function testAccessEndpointsAndOneTimeErrorsExist(): void
    {
        $controller = file_get_contents(__DIR__ . '/../../src/Module/PanelCustomer/UI/Controller/Api/InstanceSftpCredentialApiController.php');
        self::assertIsString($controller);
        self::assertStringContainsString('/access/health', $controller);
        self::assertStringContainsString('/access/reveal', $controller);
        self::assertStringContainsString('/access/reset', $controller);
        self::assertStringContainsString('SECRET_ALREADY_VIEWED', $controller);
        self::assertStringContainsString('SECRET_EXPIRED', $controller);
        self::assertStringContainsString("'ok' => true", $controller);
        self::assertStringContainsString("'ok' => false", $controller);
        self::assertStringContainsString('syncCredentialHealth', $controller);
        self::assertStringContainsString('resolvePreferredBackend', $controller);
        self::assertStringContainsString('getAccessCapabilities', $controller);
        self::assertStringContainsString("setBackend('NONE')", $controller);
        self::assertStringContainsString('sftp_agent_timeout', $controller);
        self::assertStringContainsString('sftp_agent_unreachable', $controller);
        self::assertStringContainsString('HTTP_GATEWAY_TIMEOUT', $controller);
    }
}
