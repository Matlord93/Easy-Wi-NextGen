<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\GameTemplateSeeder;

final class AdminTemplateControllerSeededTest extends AbstractWebTestCase
{
    public function testAdminTemplatesPageShowsTemplateKeys(): void
    {
        $this->seedSite();

        self::bootKernel();
        self::getContainer()->get(GameTemplateSeeder::class)->seedTemplatesOnly();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $this->loginAsRole($client, 'admin');

        $client->request('GET', '/admin/templates');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Key: cs2', $client->getResponse()->getContent() ?? '');
    }
}
