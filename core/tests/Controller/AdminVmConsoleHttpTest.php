<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Nodes\Application\Console\VmConsoleDiagnostics;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AdminVmConsoleHttpTest extends AbstractWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        self::ensureKernelShutdown();
    }

    public function testAdminCanInspectSetupWithoutReceivingAnySecret(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->loginAsRole($client, 'admin');
        $secret = str_repeat('s', 64);
        self::getContainer()->set(VmConsoleDiagnostics::class, new VmConsoleDiagnostics(new MockHttpClient(new MockResponse('{"status":"ok","protocol_version":1,"public_origin":"https://panel.test:8443"}')), $secret));
        $client->request('GET', '/admin/vm-console');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-console-setup', $html);
        self::assertStringContainsString('setup-vm-console.sh', $html);
        self::assertStringContainsString('https://panel.test:8443', $html);
        self::assertStringNotContainsString($secret, $html);
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testCustomerCannotReadAdminConsoleDiagnostics(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'customer');
        $client->request('GET', '/admin/vm-console');
        self::assertNotSame(200, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('data-console-setup', (string) $client->getResponse()->getContent());
    }
}
