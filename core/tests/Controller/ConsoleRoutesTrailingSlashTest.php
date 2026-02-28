<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class ConsoleRoutesTrailingSlashTest extends TestCase
{
    public function testConsoleStreamControllerHasTrailingSlashAlias(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Module/Gameserver/UI/Controller/Customer/CustomerInstanceConsoleStreamController.php');
        self::assertIsString($source);
        self::assertStringContainsString("path: '/{id}/console/stream/'", $source);
    }

    public function testAdminDiagnosticsControllerHasTrailingSlashAlias(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Module/PanelAdmin/UI/Controller/Admin/AdminConsoleDiagnosticsController.php');
        self::assertIsString($source);
        self::assertStringContainsString("path: '/console-stream/'", $source);
    }
}
