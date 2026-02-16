<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class CustomerInstanceBackupsDownloadApiTest extends TestCase
{
    public function testDownloadEndpointAndErrorCodesAreDefined(): void
    {
        $controller = file_get_contents(__DIR__.'/../../src/Module/Gameserver/UI/Controller/Customer/CustomerInstanceActionApiController.php');
        self::assertIsString($controller);

        self::assertStringContainsString("/api/instances/{id}/backups/{backupId}/download", $controller);
        self::assertStringContainsString("'NOT_FOUND'", $controller);
        self::assertStringContainsString("'CONFLICT'", $controller);
        self::assertStringContainsString("'FORBIDDEN'", $controller);
    }

    public function testBackupsNormalizationContainsSizeBytesField(): void
    {
        $controller = file_get_contents(__DIR__.'/../../src/Module/Gameserver/UI/Controller/Customer/CustomerInstanceActionApiController.php');
        self::assertIsString($controller);
        self::assertStringContainsString('\'size_bytes\' => $backup->getSizeBytes()', $controller);
    }
}
