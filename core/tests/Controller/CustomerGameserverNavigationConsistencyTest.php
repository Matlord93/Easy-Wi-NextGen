<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class CustomerGameserverNavigationConsistencyTest extends TestCase
{
    public function testNamedTabRoutesAreUsedForPrimaryNavigation(): void
    {
        $controller = file_get_contents(__DIR__.'/../../src/Module/Gameserver/UI/Controller/Customer/CustomerInstanceController.php');
        self::assertIsString($controller);

        self::assertStringContainsString("generate('customer_instance_overview_page'", $controller);
        self::assertStringContainsString("generate('customer_instance_console_page'", $controller);
        self::assertStringContainsString("generate('customer_instance_backups_page'", $controller);
        self::assertStringContainsString("generate('customer_instance_tasks_page'", $controller);
        self::assertStringContainsString("generate('customer_instance_settings_page'", $controller);
        self::assertStringContainsString("generate('customer_instance_files'", $controller);
    }

    public function testPrimaryShowTemplateDoesNotHardcodeLegacyTabLinks(): void
    {
        $template = file_get_contents(__DIR__.'/../../templates/customer/instances/show.html.twig');
        self::assertIsString($template);

        self::assertStringContainsString('href="{{ tab.href }}"', $template);
        self::assertStringNotContainsString("path('customer_instance_detail', {id: instance.id, tab:", $template);
    }
}
