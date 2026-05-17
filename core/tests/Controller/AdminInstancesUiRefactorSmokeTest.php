<?php

declare(strict_types=1);

namespace App\Tests\Controller;

final class AdminInstancesUiRefactorSmokeTest extends AbstractWebTestCase
{
    public function testAdminInstancesLegacyRouteLoadsWithActions(): void
    {
        putenv('APP_UI_REFACTOR_FLAGS=');
        putenv('APP_UI_REFACTOR_INSTANCES=0');

        $this->seedSite();
        $instance = $this->seedInstance();
        $instance->setServerName('Smoke Hostname');
        self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)->flush();
        static::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginAsRole($client, 'admin');

        $client->request('GET', '/admin/instances');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('id="instances-table"', $html);
        self::assertStringContainsString('/admin/instances/provision', $html);

        $client->request('GET', '/admin/instances/table');
        self::assertResponseIsSuccessful();
        $table = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Kunde-Einstellungen', $table);
        self::assertStringContainsString('Smoke Hostname', $table);
        self::assertStringContainsString('hx-post="/admin/instances/', $table);
    }

    public function testAdminInstancesRefactorRouteLoadsWithCoreActions(): void
    {
        putenv('APP_UI_REFACTOR_FLAGS=instances');
        putenv('APP_UI_REFACTOR_INSTANCES=1');

        $this->seedSite();
        $instance = $this->seedInstance();
        $instance->setServerName('Refactor Hostname');
        self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)->flush();
        static::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginAsRole($client, 'admin');

        $client->request('GET', '/admin/instances');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('id="instances-table"', $html);
        self::assertStringContainsString('/admin/instances/provision', $html);

        $client->request('GET', '/admin/instances/table');
        self::assertResponseIsSuccessful();
        $table = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Kunde-Einstellungen', $table);
        self::assertStringContainsString('Refactor Hostname', $table);
        self::assertStringContainsString('hx-post="/admin/instances/', $table);
    }
}
