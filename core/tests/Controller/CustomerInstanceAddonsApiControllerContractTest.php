<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class CustomerInstanceAddonsApiControllerContractTest extends TestCase
{
    public function testAddonsEndpointsAndErrorCodesExist(): void
    {
        $controller = file_get_contents(__DIR__.'/../../src/Module/Gameserver/UI/Controller/Customer/CustomerInstanceAddonsApiController.php');
        self::assertIsString($controller);

        self::assertStringContainsString('/api/instances/{id}/addons', $controller);
        self::assertStringContainsString('/api/instances/{id}/addons/health', $controller);
        self::assertStringContainsString('/api/instances/{id}/addons/{addonId}/install', $controller);
        self::assertStringContainsString('/api/instances/{id}/addons/{addonId}/update', $controller);
        self::assertStringContainsString('/api/instances/{id}/addons/{addonId}', $controller);

        self::assertStringContainsString("'FORBIDDEN'", $controller);
        self::assertStringContainsString("'UNAUTHORIZED'", $controller);
        self::assertStringContainsString("'NOT_FOUND'", $controller);
        self::assertStringContainsString("'INVALID_INPUT'", $controller);
        self::assertStringContainsString("'INCOMPATIBLE'", $controller);
    }

    public function testInstallRequiresConfirmation(): void
    {
        $controller = file_get_contents(__DIR__.'/../../src/Module/Gameserver/UI/Controller/Customer/CustomerInstanceAddonsApiController.php');
        self::assertIsString($controller);
        self::assertStringContainsString('Confirmation is required.', $controller);
        self::assertStringContainsString('HTTP_UNPROCESSABLE_ENTITY', $controller);
    }
}
