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

    public function testInstanceEntityTracksSharedStorageFlag(): void
    {
        $instance = $this->buildMinimalInstance();

        self::assertFalse($instance->isSharedStorageEnabled());

        $instance->setSharedStorageEnabled(true);
        self::assertTrue($instance->isSharedStorageEnabled());

        $instance->setSharedStorageEnabled(false);
        self::assertFalse($instance->isSharedStorageEnabled());
    }

    private function buildMinimalInstance(): \App\Module\Core\Domain\Entity\Instance
    {
        $template = new \App\Module\Core\Domain\Entity\Template(
            'game', 'Game', null, null, null, [], 'start', [], [], [], [], 'install', 'update',
            [], [], [], [], ['linux'], [], [],
        );
        $customer = new \App\Module\Core\Domain\Entity\User('test@example.com', \App\Module\Core\Domain\Enum\UserType::Customer);
        $agent = new \App\Module\Core\Domain\Entity\Agent('node-1', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c']);

        return new \App\Module\Core\Domain\Entity\Instance(
            $customer, $template, $agent, 100, 1024, 10240, null,
            \App\Module\Core\Domain\Enum\InstanceStatus::PendingSetup,
        );
    }
}
