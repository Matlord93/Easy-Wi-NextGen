<?php

declare(strict_types=1);

namespace App\Tests\Frontend;

use PHPUnit\Framework\TestCase;

final class GameserverSharedStorageUiSmokeTest extends TestCase
{
    public function testReinstallAppSendsUseSharedStorageFlag(): void
    {
        $script = file_get_contents(__DIR__.'/../../public/js/gameserver/reinstall-app.js');
        self::assertIsString($script);

        self::assertStringContainsString('use_shared_storage', $script);
        self::assertStringContainsString('gs-reinstall-use-shared-storage', $script);
        self::assertStringContainsString('shared_storage', $script);
    }

    public function testReinstallTemplateRendersSharedStorageControls(): void
    {
        $template = file_get_contents(__DIR__.'/../../templates/customer/instances/tabs/reinstall.html.twig');
        self::assertIsString($template);

        self::assertStringContainsString('gs-reinstall-use-shared-storage', $template);
        self::assertStringContainsString('gs-reinstall-shared-storage-hint', $template);
    }

    public function testAdminCreateFormRendersSharedStorageControls(): void
    {
        $template = file_get_contents(__DIR__.'/../../templates/admin/instances/_form.html.twig');
        self::assertIsString($template);

        self::assertStringContainsString('name="use_shared_storage"', $template);
        self::assertStringContainsString('data-shared-storage-supported', $template);
        self::assertStringContainsString("admin_instances_shared_storage_hint_unsupported", $template);
    }
}
