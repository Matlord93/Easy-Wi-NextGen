<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class CustomerInstancesListContractTest extends TestCase
{
    public function testNormalizeInstanceContainsOverviewFields(): void
    {
        $controller = file_get_contents(__DIR__ . '/../../src/Module/Gameserver/UI/Controller/Customer/CustomerInstanceController.php');
        self::assertIsString($controller);
        self::assertStringContainsString("'query' => \$querySnapshot", $controller);
        self::assertStringContainsString("'query_checked_at'", $controller);
        self::assertStringContainsString("'power' => \$powerState", $controller);
        self::assertStringContainsString("'display_status'", $controller);
    }

    public function testInstancesListTemplateContainsCardDataAttributes(): void
    {
        $template = file_get_contents(__DIR__ . '/../../templates/customer/instances/_summary_card.html.twig');
        self::assertIsString($template);
        self::assertStringContainsString('data-instance-id="{{ instance.id }}"', $template);
        self::assertStringContainsString('data-query-url="{{ path(\'customer_instance_query_api\'', $template);
        self::assertStringContainsString('data-query-health-url="{{ path(\'customer_instance_query_health_api_v2\'', $template);
        self::assertStringContainsString('data-power-url="{{ path(\'customer_instance_power_api\'', $template);
        self::assertStringContainsString('data-url-overview="{{ path(\'customer_instance_overview_page\'', $template);
    }
}
