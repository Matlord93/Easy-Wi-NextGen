<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class CustomerConfigTemplatesContractTest extends TestCase
{
    public function testConfigTemplateEndpointsAndEnvelopeShapeExist(): void
    {
        $controller = (string) file_get_contents(__DIR__.'/../../src/Module/Gameserver/UI/Controller/Customer/CustomerInstanceConfigApiController.php');
        self::assertStringContainsString('/api/instances/{id}/configs/templates', $controller);
        self::assertStringContainsString('/api/instances/{id}/configs/templates/{targetId}', $controller);
        self::assertStringContainsString('/api/instances/{id}/configs/health', $controller);
        self::assertStringContainsString("'ok' => true", $controller);
        self::assertStringContainsString("'ok' => false", $controller);
        self::assertStringContainsString("'request_id'", $controller);
        self::assertStringContainsString('UNSUPPORTED_CONFIG_TARGET', $controller);
        self::assertStringContainsString("'instance.backup.restore'", $controller);
        self::assertStringContainsString("'instance.reinstall'", $controller);
        self::assertStringContainsString('Config apply blocked while lifecycle operation is running.', $controller);
    }
}
