<?php

declare(strict_types=1);

namespace App\Tests\Frontend;

use PHPUnit\Framework\TestCase;

final class GameserverAddonsAppSmokeTest extends TestCase
{
    public function testAddonsAppMountsAndUsesSharedModules(): void
    {
        $script = file_get_contents(__DIR__.'/../../public/js/gameserver/addons-app.js');
        self::assertIsString($script);

        self::assertStringContainsString("mount('#gameserver-addons')", $script);
        self::assertStringContainsString('EasyWiGameserver', $script);
        self::assertStringContainsString('errors.showAll', $script);
    }

    public function testAddonsTemplateProvidesRequiredDatasets(): void
    {
        $template = file_get_contents(__DIR__.'/../../templates/customer/instances/tabs/addons.html.twig');
        self::assertIsString($template);

        self::assertStringContainsString('data-url-health', $template);
        self::assertStringContainsString('data-url-list', $template);
        self::assertStringContainsString('data-url-install-template', $template);
        self::assertStringContainsString('data-url-update-template', $template);
        self::assertStringContainsString('data-url-remove-template', $template);
    }
}
