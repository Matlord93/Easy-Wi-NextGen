<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Nodes\Application\Proxmox\ProxmoxApiClient;
use App\Module\Nodes\Domain\Entity\ProxmoxCluster;
use App\Module\Nodes\Domain\Entity\ProxmoxGuestAssignment;
use App\Module\Nodes\Domain\Entity\ProxmoxTask;
use App\Module\Nodes\Domain\Proxmox\ProxmoxConnection;
use App\Tests\Support\ProxmoxFixture;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class ProxmoxHttpTest extends AbstractWebTestCase
{
    private int $clusterId;
    private int $ownId;
    private int $otherId;
    private int $customerId;
    private ProxmoxFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        self::bootKernel();
        $instance = $this->seedInstance();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $cluster = new ProxmoxCluster('PVE test', new ProxmoxConnection('https://pve.example.test:8006', 'panel@pve!test', 'synthetic-pve-secret'), self::getContainer()->get(SecretsCrypto::class));
        $other = new User('other-proxmox@example.test', UserType::Customer);
        $other->setPasswordHash('unused');
        $own = new ProxmoxGuestAssignment($cluster, 101, 'qemu', $instance->getCustomer(), 'easywi-owned-0123456789abcdef', 'local');
        $foreign = new ProxmoxGuestAssignment($cluster, 102, 'lxc', $other, 'easywi-owned-fedcba9876543210', 'local');
        foreach ([$cluster, $other, $own, $foreign] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $this->clusterId = $cluster->getId();
        $this->ownId = $own->getId();
        $this->otherId = $foreign->getId();
        $this->customerId = $instance->getCustomer()->getId();
        $this->fixture = new ProxmoxFixture();
        self::ensureKernelShutdown();
    }

    public function testDiskControlsReflectStateAndRequireAdminCsrfAndConfirmation(): void
    {
        $client = $this->client();
        $this->fixture->configs[101] = ['scsi0' => 'local-lvm:vm-101-disk-0,size=32G'];
        $url = '/admin/proxmox/'.$this->clusterId.'/guests/qemu/101';
        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filterXPath('//*[@data-series-role="node"]'));
        self::assertCount(1, $crawler->filterXPath('//*[@data-vm-disk-grow]//button[@disabled]'));
        self::assertStringContainsString('Snapshots', $crawler->filterXPath('//*[@data-vm-disk-grow]')->text());
        $this->fixture->snapshots = [['name' => 'current']];
        $crawler = $client->request('GET', $url);
        self::assertCount(0, $crawler->filterXPath('//*[@data-vm-disk-grow]//button[@disabled]'));
        self::assertSame('33', $crawler->filterXPath('//*[@data-vm-disk-grow]//input[@name="size_gib"]')->attr('min'));
        $body = ['action' => 'disk-grow', 'disk' => 'scsi0', 'expected_bytes' => '34359738368', 'expected_digest' => 'fixture-digest', 'size_gib' => '40', 'confirm' => '1', 'request_key' => bin2hex(random_bytes(16))];
        $client->request('POST', $url.'/action', $body + ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->fixture->writes());
        $token = self::getContainer()->get(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class)->getToken('proxmox-action-'.$this->clusterId.'-qemu-101')->getValue();
        $client->request('POST', $url.'/action', $body + ['_token' => $token]);
        self::assertResponseStatusCodeSame(303);
        self::assertCount(1, $this->fixture->writes());
        $crawler = $client->request('GET', $url);
        self::assertSame('42949672960', $crawler->filterXPath('//*[@data-vm-disk-grow]//input[@name="expected_bytes"]')->attr('value'));
        self::ensureKernelShutdown();
        $client = $this->client(false);
        $crawler = $client->request('GET', '/customer/proxmox/'.$this->ownId);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filterXPath('//*[@data-vm-disk-grow]'));
        $token = self::getContainer()->get(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class)->getToken('proxmox-customer-'.$this->ownId)->getValue();
        $client->request('POST', '/customer/proxmox/'.$this->ownId.'/action', array_replace($body, ['_token' => $token, 'request_key' => bin2hex(random_bytes(16))]));
        self::assertCount(1, $this->fixture->writes());
    }

    public function testLanguageSwitchUpdatesTranslatedDiskControlsImmediatelyAndPersists(): void
    {
        $client = $this->client();
        $this->fixture->configs[101] = ['scsi0' => 'local:disk,size=32G'];
        $url = '/admin/proxmox/'.$this->clusterId.'/guests/qemu/101';
        foreach (['?lang=en' => 'Expand disk', '' => 'Expand disk', '?lang=de' => 'Festplatte vergrößern'] as $query => $title) {
            $crawler = $client->request('GET', $url.$query);
            self::assertResponseIsSuccessful();
            self::assertSame($title, $crawler->filterXPath('//*[@data-vm-disk-grow]//h2')->text());
        }
    }

    public function testAdminCanViewResourcesAndGuestWithoutExposingToken(): void
    {
        $client = $this->client();
        $client->request('GET', '/admin/proxmox');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('synthetic-pve-secret', (string) $client->getResponse()->getContent());
        $client->request('GET', '/admin/proxmox/'.$this->clusterId);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        foreach (['pve-a', 'pve-b', 'debian-template', 'web', 'cache'] as $value) {
            self::assertStringContainsString($value, $html);
        }
        $client->request('GET', '/admin/proxmox/'.$this->clusterId.'/guests/qemu/101');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('before-update', $html);
        self::assertStringContainsString('debian.iso', $html);
        self::assertStringNotContainsString('vzdump-qemu-999', $html);
        self::assertStringNotContainsString('synthetic-pve-secret', $html);
    }

    public function testGuestAgentEndpointRequiresOwnedVmAndCsrfBeforeProviderCalls(): void
    {
        $client = $this->client(false);
        $before = count($this->fixture->requests);
        $client->request('POST', '/customer/proxmox/'.$this->otherId.'/guest-agent', []);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/customer/proxmox/'.$this->ownId.'/guest-agent', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertCount($before, $this->fixture->requests);
        $token = self::getContainer()->get(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class)->getToken('proxmox-customer-'.$this->ownId)->getValue();
        $this->fixture->guests[0]['status'] = 'running';
        $this->fixture->configs[101] = ['agent' => '1'];
        $client->request('POST', '/customer/proxmox/'.$this->ownId.'/guest-agent', ['_token' => $token, 'vm_id' => '102', 'command' => 'guest-exec']);
        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('reachable', $data['guest_agent']['state']);
        self::assertSame('192.0.2.10', $data['guest_agent']['network']['interfaces'][0]['addresses'][0]['address']);
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame([], $this->fixture->writes());
        $client->request('POST', '/admin/proxmox/'.$this->clusterId.'/guests/qemu/101/guest-agent', ['_token' => $token]);
        self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);
    }

    public function testCustomerUsageChecksOwnershipBeforeContactingProxmox(): void
    {
        $client = $this->client(false);
        $before = count($this->fixture->requests);
        $client->request('GET', '/customer/proxmox/'.$this->otherId.'/usage');
        self::assertResponseStatusCodeSame(404);
        self::assertCount($before, $this->fixture->requests);
        $client->request('GET', '/customer/proxmox/'.$this->ownId.'/usage');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
        $html = json_decode((string) $client->getResponse()->getContent(), true)['html'];
        self::assertStringContainsString('data-usage-value', $html);
        self::assertStringNotContainsString('data-series-role="node"', $html);
        foreach (['synthetic-pve-secret', 'pve.example.test', 'pve-a', 'pve-b', 'easywi-owned-'] as $private) {
            self::assertStringNotContainsString($private, $html);
        }
        $this->fixture->guests[0]['tags'] = 'changed-owner';
        $client->request('GET', '/customer/proxmox/'.$this->ownId.'/usage');
        self::assertResponseStatusCodeSame(503);
    }

    public function testCustomerPageAndRefreshExposeGuestFilesystemButNeverNodeMetrics(): void
    {
        $client = $this->client(false);
        // Keep cache assertions independent of provider samples left by another test run.
        self::getContainer()->set(\App\Module\Nodes\Application\Proxmox\ProxmoxGuestAgent::class, new \App\Module\Nodes\Application\Proxmox\ProxmoxGuestAgent($this->fixture->client(), new \Symfony\Component\Cache\Adapter\ArrayAdapter()));
        $this->fixture->guests[0]['status'] = 'running';
        $this->fixture->configs[101] = ['agent' => 'enabled=1'];
        $this->fixture->guestFilesystems = [['name' => 'sda1', 'mountpoint' => '/', 'type' => 'ext4', 'used-bytes' => 250, 'total-bytes' => 1000, 'private' => 'secret-filesystem-data']];
        $crawler = $client->request('GET', '/customer/proxmox/'.$this->ownId);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filterXPath('//*[@data-series-role="node"]'));
        self::assertCount(1, $crawler->filterXPath('//*[@data-series-role="server"]'));
        $sample = json_decode($crawler->filterXPath('//*[@data-history-sample]')->text(), true);
        self::assertSame(250, $sample['disk_used_bytes']);
        self::assertSame(1000, $sample['disk_total_bytes']);
        $client->request('GET', '/customer/proxmox/'.$this->ownId.'/usage');
        self::assertResponseIsSuccessful();
        $html = json_decode((string) $client->getResponse()->getContent(), true)['html'];
        foreach (['data-series-role="node"', 'secret-filesystem-data', 'sda1', 'pve-a'] as $private) {
            self::assertStringNotContainsString($private, $html);
        }
        $reads = array_filter($this->fixture->requests, static fn (array $r): bool => str_ends_with($r['path'], '/agent/get-fsinfo'));
        self::assertCount(1, $reads, 'Page and polling must share the bounded guest-filesystem cache.');
        self::assertSame([], $this->fixture->writes());
    }

    public function testErofsRootProducesCompleteUsageButGenuinePartialUsageIsClearlyScoped(): void
    {
        $client = $this->client(false);
        self::getContainer()->set(\App\Module\Nodes\Application\Proxmox\ProxmoxGuestAgent::class, new \App\Module\Nodes\Application\Proxmox\ProxmoxGuestAgent($this->fixture->client(), new \Symfony\Component\Cache\Adapter\ArrayAdapter()));
        $this->fixture->guests[0]['status'] = 'running';
        $this->fixture->configs[101] = ['agent' => 'enabled=1'];
        $this->fixture->guestFilesystems = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/guest-filesystem-erofs-zram.json'), true, 32, JSON_THROW_ON_ERROR);
        $crawler = $client->request('GET', '/customer/proxmox/'.$this->ownId.'?lang=de');
        self::assertResponseIsSuccessful();
        self::assertSame('13.8 %', $crawler->filterXPath('//*[@data-usage-value="disk"]')->text());
        self::assertCount(0, $crawler->filterXPath('//*[@data-disk-scope]'));
        $sample = json_decode($crawler->filterXPath('//*[@data-history-sample]')->text(), true);
        self::assertFalse($sample['disk_partial']);
        self::assertSame(17901221888, $sample['disk_used_bytes']);
        // Use a fresh client/cache for a genuinely incomplete subsequent response.
        $this->fixture->guestFilesystems[] = ['name' => 'unmeasured', 'mountpoint' => '/data', 'type' => 'ext4'];
        self::ensureKernelShutdown();
        $client = $this->client(false);
        self::getContainer()->set(\App\Module\Nodes\Application\Proxmox\ProxmoxGuestAgent::class, new \App\Module\Nodes\Application\Proxmox\ProxmoxGuestAgent($this->fixture->client(), new \Symfony\Component\Cache\Adapter\ArrayAdapter()));
        $crawler = $client->request('GET', '/customer/proxmox/'.$this->ownId.'?lang=de');
        self::assertResponseIsSuccessful();
        self::assertSame('13.8 %', $crawler->filterXPath('//*[@data-usage-value="disk"]')->text());
        self::assertCount(1, $crawler->filterXPath('//*[@data-disk-scope]'));
        $sample = json_decode($crawler->filterXPath('//*[@data-history-sample]')->text(), true);
        self::assertTrue($sample['disk_partial']);
        self::assertNull($sample['disk_percent'], 'A partial display must not become a full-VM monitoring percentage.');
        self::assertCount(0, $crawler->filterXPath('//*[@data-series-role="node"]'));
    }

    public function testCustomerListAutomaticallyLoadsOwnedFilesystemSummaryWithoutIoOrNodeData(): void
    {
        $client = $this->client(false);
        self::getContainer()->set(\App\Module\Nodes\Application\Proxmox\ProxmoxGuestAgent::class, new \App\Module\Nodes\Application\Proxmox\ProxmoxGuestAgent($this->fixture->client(), new \Symfony\Component\Cache\Adapter\ArrayAdapter()));
        $this->fixture->guests[0]['status'] = 'running';
        $this->fixture->guests[0]['uptime'] = 600;
        $this->fixture->configs[101] = ['agent' => 'enabled=1'];
        $this->fixture->guestFilesystems = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/guest-filesystem-erofs-zram.json'), true, 32, JSON_THROW_ON_ERROR);
        $crawler = $client->request('GET', '/customer/proxmox?lang=de');
        self::assertResponseIsSuccessful();
        $panel = $crawler->filterXPath('//*[@data-infra-usage-url and @data-infra-refresh-immediate]');
        self::assertCount(1, $panel);
        self::assertSame('/customer/proxmox/'.$this->ownId.'/usage?view=summary', $panel->attr('data-infra-usage-url'));
        self::assertStringContainsString('wird geladen', $panel->filterXPath('.//*[@data-disk-source]')->text());
        self::assertStringNotContainsString('installiert', $panel->filterXPath('.//*[@data-disk-source]')->text());
        self::assertCount(0, $crawler->filterXPath('//*[@data-series-role="node"]'));
        self::assertCount(1, $this->fixture->requests, 'The initial list must not block on per-VM agent calls.');

        $before = count($this->fixture->requests);
        $client->request('GET', '/customer/proxmox/'.$this->otherId.'/usage?view=summary');
        self::assertResponseStatusCodeSame(404);
        self::assertCount($before, $this->fixture->requests);
        $client->request('GET', $panel->attr('data-infra-usage-url'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
        $html = json_decode((string) $client->getResponse()->getContent(), true)['html'];
        $fragment = new \Symfony\Component\DomCrawler\Crawler($html);
        self::assertSame('13.8 %', $fragment->filterXPath('//*[@data-usage-value="disk"]')->text());
        self::assertCount(0, $fragment->filterXPath('//h2'));
        self::assertCount(0, $fragment->filterXPath('//*[@data-usage-rate]'));
        foreach (['data-series-role="node"', 'pve-a', 'synthetic-pve-secret', 'Gast-Belegung nicht verfügbar'] as $private) {
            self::assertStringNotContainsString($private, $html);
        }
        self::assertSame([], array_values(array_filter($this->fixture->requests, static fn (array $r): bool => str_ends_with($r['path'], '/rrddata'))));
        self::assertSame([], $this->fixture->writes());

        $before = count(array_filter($this->fixture->requests, static fn (array $r): bool => str_ends_with($r['path'], '/agent/get-fsinfo')));
        $this->fixture->configTags[101] = 'changed-owner';
        $client->request('GET', '/customer/proxmox/'.$this->ownId.'/usage?view=summary');
        self::assertResponseStatusCodeSame(503);
        self::assertSame($before, count(array_filter($this->fixture->requests, static fn (array $r): bool => str_ends_with($r['path'], '/agent/get-fsinfo'))));
    }

    public function testAdminTableLoadsCompactDiskUsageAndRetainsFullDetailUsage(): void
    {
        $client = $this->client();
        self::getContainer()->set(\App\Module\Nodes\Application\Proxmox\ProxmoxGuestAgent::class, new \App\Module\Nodes\Application\Proxmox\ProxmoxGuestAgent($this->fixture->client(), new \Symfony\Component\Cache\Adapter\ArrayAdapter()));
        $this->fixture->guests[0]['status'] = 'running';
        $this->fixture->guests[0]['uptime'] = 600;
        $this->fixture->configs[101] = ['agent' => 'enabled=1'];
        $this->fixture->guestFilesystems = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/guest-filesystem-erofs-zram.json'), true, 32, JSON_THROW_ON_ERROR);
        $crawler = $client->request('GET', '/admin/proxmox/'.$this->clusterId);
        self::assertResponseIsSuccessful();
        $cells = $crawler->filterXPath('//td[@data-infra-usage-url and @data-infra-refresh-immediate and @data-pve-disk-key]');
        self::assertCount(1, $cells, 'Only running QEMU VMs require asynchronous filesystem reads.');
        self::assertCount(2, $crawler->filterXPath('//*[@data-series-role="node"]'));
        self::assertCount(1, $this->fixture->requests, 'The initial overview must remain a single inventory read.');
        $url = '/admin/proxmox/'.$this->clusterId.'/guests/qemu/101/usage?view=disk';
        self::assertSame($url, $cells->attr('data-infra-usage-url'));
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
        $html = json_decode((string) $client->getResponse()->getContent(), true)['html'];
        $fragment = new \Symfony\Component\DomCrawler\Crawler($html);
        self::assertCount(1, $fragment->filterXPath('//*[@data-pve-disk-ready]'));
        self::assertSame('13.8 %', $fragment->filterXPath('//*[@data-disk-percent]')->text());
        self::assertStringContainsString('16.67 / 120.49 GiB', $html);
        foreach (['data-series-role="node"', 'data-usage-rate', '<h2', 'synthetic-pve-secret', 'sda8', '/mnt/'] as $private) {
            self::assertStringNotContainsString($private, $html);
        }
        self::assertSame([], array_values(array_filter($this->fixture->requests, static fn (array $r): bool => str_ends_with($r['path'], '/rrddata'))));
        self::assertSame([], $this->fixture->writes());

        $client->request('GET', '/admin/proxmox/'.$this->clusterId.'/guests/qemu/101/usage');
        self::assertResponseIsSuccessful();
        $html = json_decode((string) $client->getResponse()->getContent(), true)['html'];
        self::assertStringContainsString('data-series-role="node"', $html);
        self::assertStringContainsString('data-series-role="server"', $html);
        self::assertStringContainsString('data-usage-rate', $html);
        self::assertCount(1, array_filter($this->fixture->requests, static fn (array $r): bool => str_ends_with($r['path'], '/rrddata')));
        $before = count($this->fixture->requests);
        self::ensureKernelShutdown();
        $client = $this->client(false);
        $client->request('GET', $url);
        self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);
        self::assertCount($before, $this->fixture->requests);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tlsCreateModes')]
    public function testTlsPreferenceIsPersistedOnCreation(?string $selection, bool $expected): void
    {
        $client = $this->client();
        $crawler = $client->request('GET', '/admin/proxmox');
        self::assertCount(1, $crawler->filterXPath('//select[@name="verify_tls"]/option[@value="1"][@selected]'));
        $token = self::getContainer()->get(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class)->getToken('proxmox-config')->getValue();
        $body = ['_token' => $token, 'name' => 'TLS preference', 'base_url' => 'https://pve.example.test:8006', 'token_id' => 'panel@pve!test', 'token_secret' => 'synthetic-new-secret'];
        if (null !== $selection) {
            $body['verify_tls'] = $selection;
        }
        $client->request('POST', '/admin/proxmox', $body);
        self::assertResponseStatusCodeSame(303);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $cluster = $em->getRepository(ProxmoxCluster::class)->findOneBy(['name' => 'TLS preference']);
        self::assertNotNull($cluster);
        self::assertSame($expected, $cluster->isTlsVerificationEnabled());
        self::assertSame($expected, $cluster->connection(self::getContainer()->get(SecretsCrypto::class))->verifyTls);
    }

    public static function tlsCreateModes(): iterable
    {
        yield 'existing client without field' => [null, true];
        yield 'verification enabled' => ['1', true];
        yield 'verification disabled' => ['0', false];
    }

    public function testAdminCanToggleTlsVerificationWithoutReplacingTheToken(): void
    {
        $client = $this->client();
        foreach (['0', '1'] as $selection) {
            $crawler = $client->request('GET', '/admin/proxmox/'.$this->clusterId);
            $form = $crawler->filterXPath('//form[contains(@action, "/settings")]')->form(['verify_tls' => $selection]);
            $client->submit($form);
            self::assertResponseStatusCodeSame(303);
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $em->clear();
            $cluster = $em->find(ProxmoxCluster::class, $this->clusterId);
            $connection = $cluster->connection(self::getContainer()->get(SecretsCrypto::class));
            self::assertSame('1' === $selection, $connection->verifyTls);
            self::assertSame('synthetic-pve-secret', $connection->tokenSecret);
            $crawler = $client->request('GET', '/admin/proxmox/'.$this->clusterId);
            self::assertCount(1, $crawler->filterXPath('//select[@name="verify_tls"]/option[@value="'.$selection.'"][@selected]'));
            // Older clients omit the new field; an unrelated edit must retain
            // the selected verification mode and the existing secret.
            $body = $crawler->filterXPath('//form[contains(@action, "/settings")]')->form()->getPhpValues();
            unset($body['verify_tls']);
            $client->request('POST', '/admin/proxmox/'.$this->clusterId.'/settings', $body);
            self::assertResponseStatusCodeSame(303);
            $em->clear();
            self::assertSame('1' === $selection, $em->find(ProxmoxCluster::class, $this->clusterId)->isTlsVerificationEnabled());
        }
    }

    public function testPowerActionRequiresCsrfAndIsIdempotent(): void
    {
        $client = $this->client();
        $url = '/admin/proxmox/'.$this->clusterId.'/guests/qemu/101/action';
        $body = ['action' => 'start', 'request_key' => bin2hex(random_bytes(16))];
        $client->request('POST', $url, $body + ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->fixture->writes());
        $token = self::getContainer()->get(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class)->getToken('proxmox-action-'.$this->clusterId.'-qemu-101')->getValue();
        for ($i = 0; $i < 2; ++$i) {
            $client->request('POST', $url, $body + ['_token' => $token]);
            self::assertResponseStatusCodeSame(303);
        }
        self::assertCount(1, $this->fixture->writes());
        $task = self::getContainer()->get(EntityManagerInterface::class)->getRepository(ProxmoxTask::class)->findOneBy(['requestKey' => $body['request_key']]);
        $this->fixture->complete = true;
        $client->request('GET', '/admin/proxmox/'.$this->clusterId.'/tasks/'.$task->getId());
        self::assertResponseIsSuccessful();
        self::assertSame('succeeded', json_decode((string) $client->getResponse()->getContent(), true)['state']);
    }

    public function testCustomerCanSeeOnlyAssignedGuestAndCannotChooseAnotherVm(): void
    {
        $client = $this->client(false);
        $client->request('GET', '/customer/proxmox');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('web', $html);
        self::assertStringNotContainsString('cache', strip_tags($html));
        $before = count($this->fixture->requests);
        $client->request('GET', '/customer/proxmox/'.$this->otherId);
        self::assertResponseStatusCodeSame(404);
        self::assertCount($before, $this->fixture->requests);
        $client->request('GET', '/customer/proxmox/'.$this->ownId);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        foreach (['synthetic-pve-secret', 'pve.example.test', 'panel@pve', 'vzdump-qemu-999', 'name="iso"'] as $private) {
            self::assertStringNotContainsString($private, $html);
        }
        $token = self::getContainer()->get(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class)->getToken('proxmox-customer-'.$this->ownId)->getValue();
        $client->request('POST', '/customer/proxmox/'.$this->ownId.'/action', ['_token' => $token, 'action' => 'start', 'vmId' => '102', 'kind' => 'lxc', 'request_key' => bin2hex(random_bytes(16))]);
        self::assertResponseStatusCodeSame(303);
        self::assertSame('/nodes/pve-a/qemu/101/status/start', $this->fixture->writes()[0]['path']);
    }

    public function testAssignmentPersistsAnOwnershipTagWithoutDiscardingOtherTags(): void
    {
        $client = $this->client();
        $this->fixture->guests[0]['tags'] .= ';existing-project';
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->find(User::class, $this->customerId)->setCustomerAccessEnabled(false);
        $em->flush();
        $token = self::getContainer()->get(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class)->getToken('proxmox-assign-'.$this->clusterId.'-qemu-101')->getValue();
        $client->request('POST', '/admin/proxmox/'.$this->clusterId.'/guests/qemu/101/assign', ['_token' => $token, 'customer_id' => $this->customerId, 'backup_storage' => 'local']);
        self::assertResponseStatusCodeSame(303);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $assignment = $em->getRepository(ProxmoxGuestAssignment::class)->findOneBy(['cluster' => $this->clusterId, 'vmId' => 101]);
        self::assertNotSame('easywi-owned-0123456789abcdef', $assignment->getIdentityTag());
        self::assertStringContainsString($assignment->getIdentityTag(), $this->fixture->guests[0]['tags']);
        self::assertStringContainsString('existing-project', $this->fixture->guests[0]['tags']);
        self::assertTrue($em->find(User::class, $this->customerId)->isCustomerAccessEnabled());
    }

    public function testUncertainTaskReviewRequiresCsrfAndChangesOnlyAuditedBookkeeping(): void
    {
        $client = $this->client();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $task = new ProxmoxTask($em->find(ProxmoxCluster::class, $this->clusterId), 'pve-a', 'qemu', 101, 'start', bin2hex(random_bytes(16)), null);
        $task->uncertain();
        $em->persist($task); $em->flush();
        $id = $task->getId();
        $url = '/admin/proxmox/'.$this->clusterId.'/tasks/'.$id;
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame(['state' => 'uncertain', 'result' => 'dispatch_unknown', 'active' => true, 'poll' => false], json_decode($client->getResponse()->getContent(), true));
        $client->request('GET', $url.'/review');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $client->getCrawler()->filterXPath('//textarea[@name="note"]'));
        $client->request('POST', $url.'/review', ['confirm' => '1', 'outcome' => 'success', 'note' => 'VM und Auftragsliste geprüft.']);
        self::assertResponseStatusCodeSame(403);
        $token = self::getContainer()->get(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class)->getToken('proxmox-task-review-'.$id)->getValue();
        $client->request('POST', $url.'/review', ['_token' => $token, 'outcome' => 'success', 'note' => 'VM und Auftragsliste geprüft.']);
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', $url.'/review', ['_token' => $token, 'confirm' => '1', 'outcome' => 'success', 'note' => 'VM und Auftragsliste geprüft.']);
        self::assertResponseRedirects($url.'/review', 303);
        self::assertSame('reviewed_success', $em->getConnection()->fetchOne('SELECT result FROM proxmox_tasks WHERE id = ?', [$id]));
        self::assertSame([], $this->fixture->writes());
    }

    public function testAdminCanAddANewDiskAndCustomerOnlySeesSanitizedSizes(): void
    {
        $client = $this->client();
        $this->fixture->snapshots = [['name' => 'current']];
        $this->fixture->configs[101] = ['scsi0' => 'local-lvm:original-private-volume,size=32G'];
        $url = '/admin/proxmox/'.$this->clusterId.'/guests/qemu/101';
        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filterXPath('//*[@data-pve-extra-disk]//form'));
        self::assertCount(0, $crawler->filterXPath('//*[@data-pve-extra-disk]//option[@value="scsi0"]'));
        $body = ['action' => 'disk-add', 'confirm' => '1', 'expected_digest' => 'fixture-digest', 'device' => 'scsi1', 'storage' => 'local-lvm', 'size_gib' => '8', 'request_key' => bin2hex(random_bytes(16))];
        $client->request('POST', $url.'/action', $body);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->fixture->writes());
        $token = self::getContainer()->get(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class)->getToken('proxmox-action-'.$this->clusterId.'-qemu-101')->getValue();
        $client->request('POST', $url.'/action', $body + ['_token' => $token]);
        self::assertResponseStatusCodeSame(303);
        self::assertCount(1, $this->fixture->writes());
        self::assertSame('local-lvm:8,backup=1', $this->fixture->writes()[0]['body']['scsi1']);
        self::ensureKernelShutdown();
        $client = $this->client(false);
        $client->request('GET', '/customer/proxmox/'.$this->ownId);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $client->getCrawler()->filterXPath('//*[@data-pve-extra-disk]'));
        self::assertStringContainsString('scsi1', $client->getCrawler()->filterXPath('//*[@data-pve-disk-inventory]')->text());
        self::assertStringNotContainsString('original-private-volume', $client->getResponse()->getContent());
    }

    public function testCustomerCanSeeUncertainStatusButCannotReviewTheTask(): void
    {
        $client = $this->client(false);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $task = new ProxmoxTask($em->find(ProxmoxCluster::class, $this->clusterId), 'pve-a', 'qemu', 101, 'start', bin2hex(random_bytes(16)), null);
        $task->uncertain(); $em->persist($task); $em->flush();
        $id = $task->getId();
        $client->request('GET', '/customer/proxmox/'.$this->ownId.'/tasks/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSame('uncertain', json_decode($client->getResponse()->getContent(), true)['state']);
        $before = count($this->fixture->requests);
        $client->request('GET', '/admin/proxmox/'.$this->clusterId.'/tasks/'.$id.'/review');
        self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);
        $client->request('POST', '/admin/proxmox/'.$this->clusterId.'/tasks/'.$id.'/review', ['confirm' => '1', 'outcome' => 'success', 'note' => 'Unberechtigter Versuch.']);
        self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);
        self::assertSame('uncertain', $em->getConnection()->fetchOne('SELECT state FROM proxmox_tasks WHERE id = ?', [$id]));
        self::assertCount($before, $this->fixture->requests);
    }

    private function client(bool $admin = true): KernelBrowser
    {
        $client = static::createClient();
        if ($admin) {
            $this->loginAsRole($client, 'admin');
        } else {
            $client->request('GET', '/');
            $client->request('POST', '/login', ['email' => 'instance-customer@example.test', 'password' => 'P@ssw0rd!']);
        }
        $client->disableReboot();
        self::getContainer()->set(ProxmoxApiClient::class, $this->fixture->client());

        return $client;
    }
}
