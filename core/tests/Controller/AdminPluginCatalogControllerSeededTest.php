<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\GameTemplateSeeder;

final class AdminPluginCatalogControllerSeededTest extends AbstractWebTestCase
{
    public function testAdminPluginsPageShowsSeededPluginEntry(): void
    {
        $this->seedSite();

        self::bootKernel();
        self::getContainer()->get(GameTemplateSeeder::class)->seed();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $this->loginAsRole($client, 'admin');

        $client->request('GET', '/admin/plugins');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('MetaMod:Source', $client->getResponse()->getContent() ?? '');
    }
}
