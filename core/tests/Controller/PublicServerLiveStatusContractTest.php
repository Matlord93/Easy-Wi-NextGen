<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class PublicServerLiveStatusContractTest extends TestCase
{
    public function testPublicServerDirectoryExposesLiveStatusEndpointAndPolling(): void
    {
        $controller = file_get_contents(__DIR__ . '/../../src/Module/PanelCustomer/UI/Controller/Public/PublicServerController.php');
        $template = file_get_contents(__DIR__ . '/../../templates/public/servers/index.html.twig');
        $list = file_get_contents(__DIR__ . '/../../templates/public/servers/_server_list.html.twig');

        self::assertStringContainsString("'/server-directory/status'", $controller);
        self::assertStringContainsString('toStatusPayload', $controller);
        self::assertStringContainsString('queueDueChecks($servers, 25)', $controller);
        self::assertStringContainsString('data-server-directory', $template);
        self::assertStringContainsString('window.setInterval', $template);
        self::assertStringContainsString('fetch(url.toString()', $template);
        self::assertStringContainsString('data-status-field="last_error"', $template);
        self::assertStringContainsString('data-server-id="{{ server.id }}"', $list);
    }

    public function testPublicServerStatusServiceCachesRequiredFieldsAndThrottles(): void
    {
        $service = file_get_contents(__DIR__ . '/../../src/Module/Core/Application/PublicServerStatusService.php');
        $admin = file_get_contents(__DIR__ . '/../../src/Module/PanelAdmin/UI/Controller/Admin/AdminPublicServerController.php');
        $command = file_get_contents(__DIR__ . '/../../src/Module/Core/Command/ServerStatusReconcileCommand.php');

        foreach (['online', 'players', 'max_players', 'map', 'name', 'last_checked_at', 'last_error', 'query_duration_ms'] as $field) {
            self::assertStringContainsString("'{$field}'", $service);
        }

        self::assertStringContainsString('MIN_REFRESH_INTERVAL_SECONDS = 15', $service);
        self::assertStringContainsString('findActiveByTypeAndPayloadField', $service);
        self::assertStringContainsString('timeout_seconds', $service);
        self::assertStringContainsString('queueCheck($server, force: true)', $admin);
        self::assertStringContainsString("name: 'app:gameserver-status:refresh'", $command);
        self::assertStringContainsString("aliases: ['server:status:reconcile']", $command);
    }
}
