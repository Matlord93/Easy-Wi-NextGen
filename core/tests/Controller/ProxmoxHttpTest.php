<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\ShopCategory;
use App\Module\Core\Domain\Entity\ShopProduct;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\ShopProductKind;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Nodes\Application\Proxmox\ProxmoxApiClient;
use App\Module\Nodes\Domain\Entity\ProxmoxCluster;
use App\Module\Nodes\Domain\Entity\ProxmoxGuestAssignment;
use App\Module\Nodes\Domain\Entity\ProxmoxTask;
use App\Module\Nodes\Domain\Proxmox\ProxmoxConnection;
use App\Tests\Support\ProxmoxFixture;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

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

    #[TestWith([false])]
    #[TestWith([true])]
    public function testCustomerReinstallPreservesTheOldDiskUntilFreshBootAndNetworkAreConfirmed(bool $preparePassword): void
    {
        $client = $this->client(false);
        $this->fixture->rootPasswordPermission = $preparePassword;
        [$productId, $hiddenId] = $this->prepareReinstall();
        $originalSerial = $preparePassword ? ',serial=existing%20id' : '';
        $this->fixture->configs[101]['scsi0'] .= $originalSerial;
        $url = '/customer/proxmox/'.$this->ownId;
        $crawler = $client->request('GET', $url.'?lang=en');
        self::assertResponseIsSuccessful();
        $form = $crawler->filterXPath('//form[@action="'.$url.'/reinstall"]');
        self::assertCount(1, $form);
        self::assertSame('Debian 13', $form->filterXPath('.//option[@value="'.$productId.'"]')->text());
        self::assertCount(0, $form->filterXPath('.//option[@value="'.$hiddenId.'"]'));
        $body = [
            '_token' => $form->filterXPath('.//input[@name="_token"]')->attr('value'),
            'request_key' => $form->filterXPath('.//input[@name="request_key"]')->attr('value'),
            'expected_digest' => $form->filterXPath('.//input[@name="expected_digest"]')->attr('value'),
            'product_id' => (string) $productId,
        ];
        $client->request('POST', '/customer/proxmox/'.$this->otherId.'/reinstall', $body + ['confirm' => '1']);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', $url.'/reinstall', array_replace($body, ['_token' => 'invalid', 'confirm' => '1']));
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $url.'/reinstall', $body);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $url.'/reinstall', array_replace($body, ['product_id' => (string) $hiddenId, 'confirm' => '1']));
        self::assertResponseRedirects($url.'#pve-access', 303);
        $client->request('POST', $url.'/reinstall', array_replace($body, ['expected_digest' => 'outdated', 'confirm' => '1']));
        self::assertResponseRedirects($url.'#pve-access', 303);
        $this->fixture->pendingConfiguration = [['key' => 'memory', 'pending' => 4096]];
        $client->request('POST', $url.'/reinstall', $body + ['confirm' => '1']);
        self::assertResponseRedirects($url.'#pve-access', 303);
        $this->fixture->pendingConfiguration = [];
        for ($i = 0; $i < 2; ++$i) {
            $client->request('POST', $url.'/reinstall', $body + ['confirm' => '1', 'prepare_root_password' => $preparePassword ? '1' : '0']);
            self::assertResponseRedirects($url.($preparePassword && 1 === $i ? '#pve-access' : '#pve-activity'), 303);
        }
        self::assertSame([], $this->fixture->writes());
        $task = $this->reinstallTask($body['request_key']);
        self::assertSame('queued', $task->getState());
        $taskUrl = $url.'/tasks/'.$task->getId();
        $client->request('GET', $taskUrl);
        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('/^local-lvm:0,import-from=local-lvm:base-900-disk-0,backup=1,serial=[0-9a-f]{20}$/D', $this->fixture->writes()[0]['body']['scsi0']);
        self::assertSame('local-lvm:vm-101-disk-0', $this->fixture->configs[101]['unused0']);
        $client->request('GET', $taskUrl);
        self::assertCount(1, $this->fixture->writes());

        $this->fixture->complete = true;
        $client->request('GET', $taskUrl);
        self::assertSame('local-lvm:vm-101-disk-1,backup=1'.$originalSerial, $this->fixture->writes()[1]['body']['scsi0']);
        $client->request('GET', $taskUrl);
        self::assertSame('32G', $this->fixture->writes()[2]['body']['size']);
        $client->request('GET', $taskUrl);
        self::assertStringEndsWith('/qemu/101/status/start', $this->fixture->writes()[3]['path']);
        $this->fixture->cloudInitMarker = '3.45 - '.gmdate(DATE_RFC2822, time() - 3600).' - v. 25.1';
        $client->request('GET', $taskUrl);
        self::assertCount(4, $this->fixture->writes());
        $this->fixture->cloudInitMarker = '3.45 - '.gmdate(DATE_RFC2822, time() + 1).' - v. 25.1';
        $network = $this->fixture->guestInterfaces;
        $this->fixture->guestInterfaces = [];
        $client->request('GET', $taskUrl);
        self::assertCount(4, $this->fixture->writes());
        $this->fixture->guestInterfaces = $network;
        $client->request('GET', $taskUrl);
        if ($preparePassword) {
            self::assertSame('setting_password', $this->reinstallTask($body['request_key'])->getReinstallation()['phase']);
            self::assertSame('local-lvm:vm-101-disk-0', $this->fixture->configs[101]['unused0']);
            $client->request('GET', $taskUrl);
        }
        self::assertSame(['delete' => 'unused0', 'digest' => 'fixture-digest'], $this->fixture->writes()[$preparePassword ? 5 : 4]['body']);
        $client->request('GET', $taskUrl);
        self::assertResponseIsSuccessful();
        self::assertSame(['state' => 'succeeded', 'result' => 'OK', 'active' => false, 'poll' => true], json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('local-lvm:vm-101-disk-1,size=32G,backup=1'.$originalSerial, $this->fixture->configs[101]['scsi0']);
        self::assertArrayNotHasKey('unused0', $this->fixture->configs[101]);
        self::assertSame('ip=192.0.2.10/24,gw=192.0.2.1', $this->fixture->configs[101]['ipconfig0']);
        self::assertSame('easywi', $this->fixture->configs[101]['ciuser']);
        if ($preparePassword) {
            self::assertStringEndsWith('/agent/set-user-password', $this->fixture->writes()[4]['path']);
            $response = $client->request('POST', $url.'/root-password/reveal', ['_token' => $body['_token']]);
            self::assertResponseIsSuccessful();
            $password = $response->filterXPath('//input[@type="text" and @readonly]')->attr('value');
            $hash = $this->fixture->writes()[4]['body']['password'];
            self::assertSame($hash, crypt($password, $hash));
        }
    }

    public function testCustomerReinstallRollsBackIfStoringThePasswordFails(): void
    {
        $client = $this->client(false);
        $this->fixture->rootPasswordPermission = true;
        [$productId] = $this->prepareReinstall();
        $url = '/customer/proxmox/'.$this->ownId;
        $form = $client->request('GET', $url)->filterXPath('//form[@action="'.$url.'/reinstall"]');
        $key = $form->filterXPath('.//input[@name="request_key"]')->attr('value');
        $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $db->executeStatement("CREATE TEMP TRIGGER fail_password_handoff BEFORE UPDATE OF root_password_handoff ON proxmox_guest_assignments BEGIN SELECT RAISE(ABORT, 'test handoff storage failure'); END");
        try {
            $client->request('POST', $url.'/reinstall', [
                '_token' => $form->filterXPath('.//input[@name="_token"]')->attr('value'),
                'request_key' => $key,
                'expected_digest' => $form->filterXPath('.//input[@name="expected_digest"]')->attr('value'),
                'product_id' => (string) $productId,
                'confirm' => '1',
                'prepare_root_password' => '1',
            ]);
            self::assertResponseStatusCodeSame(500);
            self::assertFalse($db->fetchOne('SELECT id FROM proxmox_tasks WHERE request_key = ?', [$key]));
            self::assertNull($db->fetchOne('SELECT root_password_handoff FROM proxmox_guest_assignments WHERE id = ?', [$this->ownId]));
            self::assertSame([], $this->fixture->writes());
        } finally {
            $db->executeStatement('DROP TRIGGER fail_password_handoff');
        }
    }

    #[TestWith(['lost-reply'])]
    #[TestWith(['malformed'])]
    #[TestWith(['panel-interruption'])]
    #[TestWith(['timeout'])]
    #[TestWith(['rejected'])]
    #[TestWith(['changed-config'])]
    public function testReinstallWaitsForTheConfirmedRootPasswordBeforeRemovingThePreviousDisk(string $scenario): void
    {
        $client = $this->client(false);
        $this->fixture->rootPasswordPermission = true;
        [$productId] = $this->prepareReinstall();
        $url = '/customer/proxmox/'.$this->ownId;
        $page = $client->request('GET', $url);
        $form = $page->filterXPath('//form[@action="'.$url.'/reinstall"]');
        $requestKey = $form->filterXPath('.//input[@name="request_key"]')->attr('value');
        $token = $form->filterXPath('.//input[@name="_token"]')->attr('value');
        $client->submit($form->form(['confirm' => '1', 'product_id' => (string) $productId, 'prepare_root_password' => '1']));
        self::assertResponseRedirects($url.'#pve-activity', 303);
        $taskId = $this->reinstallTask($requestKey)->getId();
        $taskUrl = $url.'/tasks/'.$taskId;
        $this->fixture->complete = true;
        for ($i = 0; $i < 4; ++$i) {
            $client->request('GET', $taskUrl);
            self::assertResponseIsSuccessful();
        }
        $this->fixture->cloudInitMarker = '3.45 - '.gmdate(DATE_RFC2822, time() + 1).' - v. 25.1';
        $passwordPath = '/nodes/pve-a/qemu/101/agent/set-user-password';
        $this->fixture->loseResponseAfterWrite = in_array($scenario, ['lost-reply', 'timeout', 'changed-config'], true) ? $passwordPath : null;
        $this->fixture->malformedRootPasswordResponse = 'malformed' === $scenario;
        $this->fixture->failWrites = 'rejected' === $scenario;
        $this->fixture->writeFailureStatus = 400;
        $client->request('GET', $taskUrl);
        self::assertResponseIsSuccessful();
        self::assertCount(5, $this->fixture->writes());
        self::assertSame($passwordPath, $this->fixture->writes()[4]['path']);
        $passwordRequest = $this->fixture->writes()[4]['body'];
        self::assertSame('local-lvm:vm-101-disk-0', $this->fixture->configs[101]['unused0']);
        $this->fixture->malformedRootPasswordResponse = $this->fixture->failWrites = false;
        $this->fixture->loseResponseAfterWrite = null;

        $em = self::getContainer()->get(EntityManagerInterface::class);
        if ('panel-interruption' === $scenario) {
            $em->getConnection()->update('proxmox_tasks', ['state' => 'submitting'], ['id' => $taskId]);
        }
        $reveal = $url.'/root-password/reveal';
        $client->request('POST', $reveal, ['_token' => $token]);
        self::assertResponseRedirects($url.'#pve-access', 303);
        $client->request('GET', $taskUrl);
        self::assertCount(5, $this->fixture->writes());
        if ('rejected' === $scenario) {
            self::assertSame('reinstall_password_rejected', json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['result']);
            self::assertSame('local-lvm:vm-101-disk-0', $this->fixture->configs[101]['unused0']);

            return;
        }
        self::assertSame('submitting', json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['state']);

        $plan = $this->reinstallTask($requestKey)->getReinstallation();
        $plan['step_submitted_at'] = time() - 30;
        if ('timeout' === $scenario) {
            $plan['password_requested_at'] = time() - 301;
        } elseif ('changed-config' === $scenario) {
            $this->fixture->configs[101]['ipconfig0'] = 'ip=192.0.2.11/24';
        }
        $em->getConnection()->update('proxmox_tasks', ['installation' => json_encode(['reinstall' => $plan], JSON_THROW_ON_ERROR)], ['id' => $taskId]);
        $client->request('GET', $taskUrl);
        $progress = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        if (in_array($scenario, ['timeout', 'changed-config'], true)) {
            self::assertSame('uncertain', $progress['state']);
            self::assertSame('timeout' === $scenario ? 'reinstall_password_unconfirmed' : 'reinstall_configuration_changed', $progress['result']);
            self::assertCount(5, $this->fixture->writes());
            self::assertSame('local-lvm:vm-101-disk-0', $this->fixture->configs[101]['unused0']);

            return;
        }

        self::assertSame('running', $progress['state']);
        self::assertCount(6, $this->fixture->writes());
        self::assertSame($passwordPath, $this->fixture->writes()[5]['path']);
        self::assertSame($passwordRequest, $this->fixture->writes()[5]['body']);
        self::assertSame('local-lvm:vm-101-disk-0', $this->fixture->configs[101]['unused0']);
        $client->request('GET', $taskUrl);
        self::assertSame(['delete' => 'unused0', 'digest' => 'fixture-digest'], $this->fixture->writes()[6]['body']);
        $client->request('GET', $taskUrl);
        self::assertSame('succeeded', json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['state']);
        $page = $client->request('POST', $reveal, ['_token' => $token]);
        self::assertResponseIsSuccessful();
        $password = $page->filterXPath('//input[@type="text" and @readonly]')->attr('value');
        self::assertSame($passwordRequest['password'], crypt($password, $passwordRequest['password']));
        $client->request('POST', $reveal, ['_token' => $token]);
        self::assertResponseRedirects($url.'#pve-access', 303);
    }

    public function testCustomerReinstallStopsWhenTheVmConfigChangesAfterImport(): void
    {
        $client = $this->client(false);
        [$productId] = $this->prepareReinstall();
        $url = '/customer/proxmox/'.$this->ownId;
        $crawler = $client->request('GET', $url);
        $form = $crawler->filterXPath('//form[@action="'.$url.'/reinstall"]');
        $requestKey = $form->filterXPath('.//input[@name="request_key"]')->attr('value');
        $client->request('POST', $url.'/reinstall', [
            '_token' => $form->filterXPath('.//input[@name="_token"]')->attr('value'),
            'request_key' => $requestKey, 'expected_digest' => 'fixture-digest', 'product_id' => (string) $productId, 'confirm' => '1',
        ]);
        self::assertResponseRedirects($url.'#pve-activity', 303);
        $taskUrl = $url.'/tasks/'.$this->reinstallTask($requestKey)->getId();
        $client->request('GET', $taskUrl);
        self::assertCount(1, $this->fixture->writes());
        $this->fixture->complete = true;
        $this->fixture->configs[101]['ipconfig0'] = 'ip=192.0.2.11/24';
        $client->request('GET', $taskUrl);
        self::assertResponseIsSuccessful();
        self::assertSame(['state' => 'uncertain', 'result' => 'reinstall_configuration_changed', 'active' => true, 'poll' => false], json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        self::assertCount(1, $this->fixture->writes());
        self::assertSame('local-lvm:vm-101-disk-0', $this->fixture->configs[101]['unused0']);
    }

    public function testCustomerCanReinstallFromTheOriginalTemplateWithoutShopProducts(): void
    {
        $client = $this->client(false);
        [$product, $hidden] = $this->prepareReinstall();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('DELETE FROM shop_products WHERE id IN (?, ?)', [$product, $hidden]);
        $em->getConnection()->insert('proxmox_tasks', [
            'cluster_id' => $this->clusterId, 'node' => 'pve-a', 'kind' => 'qemu', 'vm_id' => 900, 'target_vm_id' => 101,
            'action' => 'install-template', 'request_key' => '0123456789abcdef0123456789abcdef', 'state' => 'succeeded',
            'result' => 'OK', 'created_at' => '2026-11-20 12:00:00',
            'installation' => json_encode(['customer_id' => $this->customerId], JSON_THROW_ON_ERROR),
        ]);
        $url = '/customer/proxmox/'.$this->ownId;
        $page = $client->request('GET', $url);
        $form = $page->filterXPath('//form[@action="'.$url.'/reinstall"]');
        self::assertCount(1, $form->filterXPath('.//option[@value="0"]'));
        $requestKey = $form->filterXPath('.//input[@name="request_key"]')->attr('value');
        $client->submit($form->form(['confirm' => '1', 'product_id' => '0']));
        self::assertResponseRedirects($url.'#pve-activity', 303);
        $task = $this->reinstallTask($requestKey);
        $client->request('GET', $url.'/tasks/'.$task->getId());
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('local-lvm:0,import-from=local-lvm:base-900-disk-0,backup=1,serial=', $this->fixture->writes()[0]['body']['scsi0']);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testAdminReinstallRollbackReattachesThePreviousDiskAndRetainsTheReplacement(bool $lostResponse): void
    {
        $this->fixture->propagatePermissions = false;
        $client = $this->client(false);
        [$productId] = $this->prepareReinstall();
        $customerUrl = '/customer/proxmox/'.$this->ownId;
        $page = $client->request('GET', $customerUrl);
        $form = $page->filterXPath('//form[@action="'.$customerUrl.'/reinstall"]');
        $requestKey = $form->filterXPath('.//input[@name="request_key"]')->attr('value');
        $client->submit($form->form(['confirm' => '1', 'product_id' => (string) $productId]));
        self::assertResponseRedirects($customerUrl.'#pve-activity', 303);
        $taskId = $this->reinstallTask($requestKey)->getId();
        $customerTask = $customerUrl.'/tasks/'.$taskId;
        $this->fixture->complete = true;
        for ($i = 0; $i < 4; ++$i) {
            $client->request('GET', $customerTask);
            self::assertResponseIsSuccessful();
        }
        self::assertStringEndsWith('/qemu/101/status/start', $this->fixture->writes()[3]['path']);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->update('proxmox_tasks', ['created_at' => date('Y-m-d H:i:s', time() - 3700)], ['id' => $taskId]);
        $client->request('GET', $customerTask);
        self::assertSame('reinstall_timeout', json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['result']);
        self::assertCount(4, $this->fixture->writes());

        $adminTask = '/admin/proxmox/'.$this->clusterId.'/tasks/'.$taskId;
        $review = $adminTask.'/review';
        $client->request('POST', $review, ['outcome' => 'rollback', 'confirm' => '1']);
        self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);
        $this->loginAsRole($client, 'admin');
        $page = $client->request('GET', $review);
        self::assertResponseIsSuccessful();
        $rollback = $page->filterXPath('//form[@data-pve-restore-system-disk]');
        self::assertCount(1, $rollback);
        $fields = ['_token' => $rollback->filterXPath('.//input[@name="_token"]')->attr('value'), 'outcome' => 'rollback'];
        $client->request('POST', $review, $fields);
        self::assertResponseStatusCodeSame(422);

        $originalVolume = $this->fixture->configs[101]['unused0'];
        $this->fixture->configs[101]['unused0'] = 'local-lvm:vm-101-other-volume';
        $client->request('POST', $review, $fields + ['confirm' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(4, $this->fixture->writes());
        $this->fixture->configs[101]['unused0'] = $originalVolume;

        $this->fixture->auditPermission = false;
        $client->request('POST', $review, $fields + ['confirm' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(4, $this->fixture->writes());
        $this->fixture->auditPermission = true;

        $client->request('POST', $review, $fields + ['confirm' => '1']);
        self::assertResponseRedirects($review, 303);
        self::assertCount(4, $this->fixture->writes());
        $this->fixture->complete = false;
        $client->request('GET', $adminTask);
        self::assertStringEndsWith('/qemu/101/status/shutdown', $this->fixture->writes()[4]['path']);
        self::assertSame([], $this->fixture->writes()[4]['body']);
        $client->request('GET', $adminTask);
        self::assertCount(5, $this->fixture->writes());

        $this->fixture->complete = true;
        $this->fixture->loseDiskReattachResponse = $lostResponse;
        $client->request('GET', $adminTask);
        self::assertSame(['scsi0' => 'local-lvm:vm-101-disk-0,backup=1', 'digest' => 'fixture-digest'], $this->fixture->writes()[5]['body']);
        if ($lostResponse) {
            self::assertSame('uncertain', json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['state']);
            $client->request('POST', $review, $fields + ['confirm' => '1']);
            self::assertResponseRedirects($review, 303);
        }
        $client->request('GET', $adminTask);
        self::assertSame(['state' => 'failed', 'result' => 'reinstall_rolled_back', 'active' => false, 'poll' => true], json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        self::assertCount(6, $this->fixture->writes());
        self::assertSame('local-lvm:vm-101-disk-0,size=32G,backup=1', $this->fixture->configs[101]['scsi0']);
        self::assertSame('local-lvm:vm-101-disk-1', $this->fixture->configs[101]['unused0']);
        self::assertSame('stopped', $this->fixture->guests[0]['status']);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testAdminCanRecoverAnImportedSystemDiskBeforeThePanelRecordsTheReplacement(bool $lostResponse): void
    {
        $client = $this->client(false);
        [$productId] = $this->prepareReinstall();
        $customerUrl = '/customer/proxmox/'.$this->ownId;
        $page = $client->request('GET', $customerUrl);
        $form = $page->filterXPath('//form[@action="'.$customerUrl.'/reinstall"]');
        $requestKey = $form->filterXPath('.//input[@name="request_key"]')->attr('value');
        $client->submit($form->form(['confirm' => '1', 'product_id' => (string) $productId]));
        self::assertResponseRedirects($customerUrl.'#pve-activity', 303);
        $taskId = $this->reinstallTask($requestKey)->getId();
        $this->fixture->loseDiskImportResponse = $lostResponse;
        $client->request('GET', $customerUrl.'/tasks/'.$taskId);
        if (!$lostResponse) {
            // Simulate termination after Proxmox accepted the write and before the UPID was committed.
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $em->getConnection()->update('proxmox_tasks', ['state' => 'submitting', 'upid' => null], ['id' => $taskId]);
            $client->request('GET', $customerUrl.'/tasks/'.$taskId);
        }
        self::assertSame('uncertain', json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['state']);
        self::assertArrayNotHasKey('replacement_volume', $this->reinstallTask($requestKey)->getReinstallation());
        self::assertCount(1, $this->fixture->writes());

        $this->loginAsRole($client, 'admin');
        $taskUrl = '/admin/proxmox/'.$this->clusterId.'/tasks/'.$taskId;
        $review = $taskUrl.'/review';
        $page = $client->request('GET', $review);
        $rollback = $page->filterXPath('//form[@data-pve-restore-system-disk]');
        self::assertCount(1, $rollback);
        $fields = ['_token' => $rollback->filterXPath('.//input[@name="_token"]')->attr('value'), 'outcome' => 'rollback', 'confirm' => '1'];

        $markedDisk = $this->fixture->configs[101]['scsi0'];
        $this->fixture->configs[101]['scsi0'] = 'local-lvm:vm-101-disk-1,size=8G,backup=1,serial=other-import';
        $client->request('POST', $review, $fields);
        self::assertResponseStatusCodeSame(422);
        $this->fixture->configs[101]['scsi0'] = $markedDisk;
        $this->fixture->configs[101]['unused1'] = 'local-lvm:vm-101-unrelated-disk';
        $client->request('POST', $review, $fields);
        self::assertResponseStatusCodeSame(422);
        unset($this->fixture->configs[101]['unused1']);
        $this->fixture->activeJobs = [['id' => '101', 'type' => 'qmconfig']];
        $client->request('POST', $review, $fields);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->fixture->writes());

        $this->fixture->activeJobs = [];
        $this->fixture->complete = true;
        $client->request('POST', $review, $fields);
        self::assertResponseRedirects($review, 303);
        self::assertCount(1, $this->fixture->writes());
        $client->request('GET', $taskUrl);
        $client->request('GET', $taskUrl);
        self::assertSame('reinstall_rolled_back', json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['result']);
        self::assertCount(2, $this->fixture->writes());
        self::assertSame(['scsi0' => 'local-lvm:vm-101-disk-0,backup=1', 'digest' => 'fixture-digest'], $this->fixture->writes()[1]['body']);
        self::assertSame('local-lvm:vm-101-disk-0,size=32G,backup=1', $this->fixture->configs[101]['scsi0']);
        self::assertSame('local-lvm:vm-101-disk-1', $this->fixture->configs[101]['unused0']);
    }

    #[TestWith(['lost-reply'])]
    #[TestWith(['panel-interruption'])]
    #[TestWith(['admin-recheck'])]
    public function testReinstallCanConfirmAnUnacknowledgedRemovalWithoutDeletingAgain(string $scenario): void
    {
        $client = $this->client(false);
        [$productId] = $this->prepareReinstall();
        $customerUrl = '/customer/proxmox/'.$this->ownId;
        $page = $client->request('GET', $customerUrl);
        $form = $page->filterXPath('//form[@action="'.$customerUrl.'/reinstall"]');
        $requestKey = $form->filterXPath('.//input[@name="request_key"]')->attr('value');
        $client->submit($form->form(['confirm' => '1', 'product_id' => (string) $productId]));
        self::assertResponseRedirects($customerUrl.'#pve-activity', 303);
        $taskId = $this->reinstallTask($requestKey)->getId();
        $taskUrl = $customerUrl.'/tasks/'.$taskId;
        $this->fixture->complete = true;
        for ($i = 0; $i < 4; ++$i) {
            $client->request('GET', $taskUrl);
            self::assertResponseIsSuccessful();
        }
        $this->fixture->cloudInitMarker = '3.45 - '.gmdate(DATE_RFC2822, time() + 1).' - v. 25.1';
        $this->fixture->loseDiskRemovalResponse = 'panel-interruption' !== $scenario;
        $client->request('GET', $taskUrl);
        self::assertResponseIsSuccessful();
        self::assertSame(['delete' => 'unused0', 'digest' => 'fixture-digest'], $this->fixture->writes()[4]['body']);
        self::assertArrayNotHasKey('unused0', $this->fixture->configs[101]);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        if ('panel-interruption' === $scenario) {
            $em->getConnection()->update('proxmox_tasks', ['state' => 'submitting', 'upid' => null], ['id' => $taskId]);
        }
        $client->request('GET', $taskUrl);
        self::assertSame('submitting', json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['state']);

        $original = 'local-lvm:vm-101-disk-0';
        $replacement = 'local-lvm:vm-101-disk-1';
        $this->fixture->imageVolumes = [$original, $replacement];
        $client->request('GET', $taskUrl);
        self::assertSame('submitting', json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['state']);

        if ('admin-recheck' === $scenario) {
            $plan = $this->reinstallTask($requestKey)->getReinstallation();
            $plan['removal_requested_at'] = time() - 301;
            $em->getConnection()->update('proxmox_tasks', ['installation' => json_encode(['reinstall' => $plan], JSON_THROW_ON_ERROR)], ['id' => $taskId]);
            $client->request('GET', $taskUrl);
            self::assertSame(['state' => 'uncertain', 'result' => 'reinstall_removal_unconfirmed', 'active' => true, 'poll' => false], json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));

            $review = '/admin/proxmox/'.$this->clusterId.'/tasks/'.$taskId.'/review';
            $this->loginAsRole($client, 'admin');
            $page = $client->request('GET', $review);
            $form = $page->filterXPath('//form[.//select[@name="outcome"]]');
            self::assertCount(1, $form->filterXPath('.//option[@value="success"]'));
            $fields = ['_token' => $form->filterXPath('.//input[@name="_token"]')->attr('value'), 'outcome' => 'success', 'confirm' => '1', 'note' => 'Storage und VM im Cluster geprüft.'];
            $client->request('POST', $review, array_replace($fields, ['_token' => 'invalid']));
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', $review, $fields);
            self::assertResponseStatusCodeSame(422);
            $this->fixture->imageVolumes = [$replacement];
            $this->fixture->activeJobs = [['id' => '101', 'type' => 'qmconfig']];
            $client->request('POST', $review, $fields);
            self::assertResponseStatusCodeSame(422);
            $this->fixture->activeJobs = [];
            $client->request('POST', $review, $fields);
            self::assertResponseRedirects($review, 303);
            $taskUrl = '/admin/proxmox/'.$this->clusterId.'/tasks/'.$taskId;
        }

        $this->fixture->imageVolumes = [$replacement];
        $client->request('GET', $taskUrl);
        self::assertSame(['state' => 'succeeded', 'result' => 'OK', 'active' => false, 'poll' => true], json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        self::assertCount(5, $this->fixture->writes());
        self::assertNull($em->getConnection()->fetchOne('SELECT guest_lock FROM proxmox_tasks WHERE id = ?', [$taskId]));
    }

    #[TestWith(['restoring_options', 1, 'lost-reply'])]
    #[TestWith(['resizing', 2, 'lost-reply'])]
    #[TestWith(['starting', 3, 'lost-reply'])]
    #[TestWith(['starting', 3, 'panel-interruption'])]
    #[TestWith(['restoring_options', 1, 'never-applied'])]
    public function testReinstallReconcilesIntermediateStepsWithoutRepeatingWrites(string $phase, int $writesBefore, string $scenario): void
    {
        $client = $this->client(false);
        [$productId] = $this->prepareReinstall();
        $customerUrl = '/customer/proxmox/'.$this->ownId;
        $page = $client->request('GET', $customerUrl);
        $form = $page->filterXPath('//form[@action="'.$customerUrl.'/reinstall"]');
        $requestKey = $form->filterXPath('.//input[@name="request_key"]')->attr('value');
        $client->submit($form->form(['confirm' => '1', 'product_id' => (string) $productId]));
        self::assertResponseRedirects($customerUrl.'#pve-activity', 303);
        $taskId = $this->reinstallTask($requestKey)->getId();
        $taskUrl = $customerUrl.'/tasks/'.$taskId;
        $this->fixture->complete = true;
        for ($i = 0; $i < $writesBefore; ++$i) {
            $client->request('GET', $taskUrl);
            self::assertResponseIsSuccessful();
        }
        $operation = match ($phase) {
            'restoring_options' => '/config',
            'resizing' => '/resize',
            'starting' => '/status/start',
        };
        $path = '/nodes/pve-a/qemu/101'.$operation;
        if ('lost-reply' === $scenario) {
            $this->fixture->loseResponseAfterWrite = $path;
        } elseif ('never-applied' === $scenario) {
            $this->fixture->lostWritePath = $path;
        }
        $client->request('GET', $taskUrl);
        self::assertResponseIsSuccessful();
        self::assertSame($path, $this->fixture->writes()[$writesBefore]['path']);
        self::assertSame($phase, $this->reinstallTask($requestKey)->getReinstallation()['phase']);
        $this->fixture->loseResponseAfterWrite = $this->fixture->lostWritePath = null;

        $em = self::getContainer()->get(EntityManagerInterface::class);
        if ('panel-interruption' === $scenario) {
            $em->getConnection()->update('proxmox_tasks', ['state' => 'submitting', 'upid' => null], ['id' => $taskId]);
        }
        $this->fixture->activeJobs = [['id' => '101', 'type' => 'qmconfig']];
        $client->request('GET', $taskUrl);
        self::assertSame('submitting', json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['state']);
        self::assertCount($writesBefore + 1, $this->fixture->writes());
        $this->fixture->activeJobs = [];
        $client->request('GET', $taskUrl);
        self::assertCount($writesBefore + 1, $this->fixture->writes());

        if ('never-applied' === $scenario) {
            self::assertSame('submitting', json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['state']);
            $plan = $this->reinstallTask($requestKey)->getReinstallation();
            $plan['step_submitted_at'] = time() - 301;
            $em->getConnection()->update('proxmox_tasks', ['installation' => json_encode(['reinstall' => $plan], JSON_THROW_ON_ERROR)], ['id' => $taskId]);
            $client->request('GET', $taskUrl);
            self::assertSame(['state' => 'uncertain', 'result' => 'reinstall_step_unconfirmed', 'active' => true, 'poll' => false], json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
            self::assertCount($writesBefore + 1, $this->fixture->writes());
            self::assertSame('local-lvm:vm-101-disk-0', $this->fixture->configs[101]['unused0']);
            $this->loginAsRole($client, 'admin');
            $page = $client->request('GET', '/admin/proxmox/'.$this->clusterId.'/tasks/'.$taskId.'/review');
            self::assertCount(1, $page->filterXPath('//form[@data-pve-restore-system-disk]'));

            return;
        }

        self::assertSame('running', json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['state']);
        for ($i = $writesBefore + 1; $i < 4; ++$i) {
            $client->request('GET', $taskUrl);
            self::assertResponseIsSuccessful();
        }
        $this->fixture->cloudInitMarker = '3.45 - '.gmdate(DATE_RFC2822, time() - 3600).' - v. 25.1';
        $client->request('GET', $taskUrl);
        self::assertCount(4, $this->fixture->writes());
        self::assertSame('local-lvm:vm-101-disk-0', $this->fixture->configs[101]['unused0']);
        $this->fixture->cloudInitMarker = '3.45 - '.gmdate(DATE_RFC2822, time() + 1).' - v. 25.1';
        $client->request('GET', $taskUrl);
        $client->request('GET', $taskUrl);
        self::assertSame(['state' => 'succeeded', 'result' => 'OK', 'active' => false, 'poll' => true], json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        self::assertCount(5, $this->fixture->writes());
        self::assertArrayNotHasKey('unused0', $this->fixture->configs[101]);
    }

    /** @return array{int, int} */
    private function prepareReinstall(): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $siteId = self::getContainer()->get(SiteResolver::class)->resolve(Request::create('http://localhost/'))->getId();
        $category = new ShopCategory($siteId, 'Linux templates', 'reinstall-linux-templates');
        $product = new ShopProduct($siteId, $category, 'Debian 13', '', 1000, null, null, 2, 2, 32);
        $hidden = new ShopProduct($siteId, $category, 'Private image', '', 1000, null, null, 2, 2, 32, isPublicActive: false, isCustomerActive: false);
        $em->persist($category);
        foreach ([$product, $hidden] as $offer) {
            $offer->configureKind(ShopProductKind::Proxmox, ['proxmox_cluster_id' => $this->clusterId, 'proxmox_node' => 'pve-a', 'proxmox_template_id' => 900]);
            $em->persist($offer);
        }
        $em->flush();
        $this->fixture->configs[101] = [
            'scsi0' => 'local-lvm:vm-101-disk-0,size=32G,backup=1', 'ide2' => 'local-lvm:vm-101-cloudinit,media=cdrom',
            'boot' => 'order=scsi0', 'net0' => 'virtio=52:54:00:12:34:56,bridge=vmbr0',
            'ipconfig0' => 'ip=192.0.2.10/24,gw=192.0.2.1', 'sshkeys' => 'ssh-ed25519 synthetic', 'ciuser' => 'easywi', 'agent' => 'enabled=1',
        ];
        $this->fixture->configs[900] = [
            'template' => 1, 'scsi0' => 'local-lvm:base-900-disk-0,size=8G', 'ide2' => 'local-lvm:vm-900-cloudinit,media=cdrom',
            'net0' => 'virtio=52:54:00:12:34:00,bridge=vmbr0',
        ];
        $this->fixture->snapshots = [['name' => 'current']];

        return [$product->getId(), $hidden->getId()];
    }

    private function reinstallTask(string $requestKey): ProxmoxTask
    {
        $task = self::getContainer()->get(EntityManagerInterface::class)->getRepository(ProxmoxTask::class)->findOneBy(['requestKey' => $requestKey]);
        self::assertInstanceOf(ProxmoxTask::class, $task);

        return $task;
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

    public function testAdminInstallationChecksRootPasswordPrivilegeOnTheTargetBeforeReservingIt(): void
    {
        $client = $this->client();
        $this->fixture->configs[900] = ['ide2' => 'local-lvm:vm-900-cloudinit,media=cdrom', 'net0' => 'virtio=52:54:00:12:34:56,bridge=vmbr0'];
        $url = '/admin/proxmox/'.$this->clusterId.'/guests/qemu/900';
        $page = $client->request('GET', $url.'?lang=en');
        self::assertResponseIsSuccessful();
        $form = $page->filterXPath('//form[input[@name="action" and @value="install-template"]]');
        $requestKey = $form->filterXPath('.//input[@name="request_key"]')->attr('value');
        $client->submit($form->form([
            'name' => 'root-access-test', 'target_vm_id' => '1001',
            'ssh_keys' => \phpseclib3\Crypt\EC::createKey('Ed25519')->getPublicKey()->toString('OpenSSH'),
            'customer_id' => (string) $this->customerId, 'prepare_root_password' => '1', 'address_mode' => 'dhcp',
        ]));
        self::assertResponseRedirects($url.'#pve-activity', 303);
        $page = $client->followRedirect();
        self::assertStringContainsString('Password handoff requires VM.GuestAgent.Unrestricted on the target VM.', $page->filterXPath('//*[@role="alert"]')->text());
        self::assertContains('/vms/1001', array_column(array_column($this->fixture->requests, 'query'), 'path'));
        self::assertSame([], $this->fixture->writes());
        self::assertNull(self::getContainer()->get(EntityManagerInterface::class)->getRepository(ProxmoxTask::class)->findOneBy(['requestKey' => $requestKey]));
    }

    public function testAdminCanConfigureAddressPoolAndSelectItForInstallation(): void
    {
        $client = $this->client();
        $base = '/admin/proxmox/'.$this->clusterId;
        $url = $base.'/nodes/pve-a/address-pools';
        $crawler = $client->request('GET', $url.'?lang=en');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filterXPath('//datalist/option[@value="vmbr0"]'));
        $token = $crawler->filterXPath('//form[input[@name="family" and @value="4"]]/input[@name="_token"]')->attr('value');
        $body = ['family' => '4', 'first' => '192.0.2.10', 'last' => '192.0.2.30', 'prefix' => '24', 'bridge' => 'vmbr0', 'enabled' => '1'];
        $client->request('POST', $url, $body + ['gateway' => '192.0.2.1', '_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $url, $body + ['gateway' => '192.0.2.10', '_token' => $token]);
        self::assertResponseRedirects($url, 303);
        $crawler = $client->followRedirect();
        self::assertStringContainsString('The gateway must be outside the allocation range.', $crawler->filterXPath('//*[@role="alert"]')->text());
        $client->request('POST', $url, $body + ['gateway' => '192.0.2.1', '_token' => $token]);
        self::assertResponseRedirects($url, 303);
        $crawler = $client->followRedirect();
        self::assertCount(1, $crawler->filterXPath('//form[input[@name="family" and @value="4"]]//input[@name="first" and @value="192.0.2.10"]'));

        $otherUrl = $base.'/nodes/pve-b/address-pools';
        $crawler = $client->request('GET', $otherUrl);
        $otherToken = $crawler->filterXPath('//form[input[@name="family" and @value="4"]]/input[@name="_token"]')->attr('value');
        $client->request('POST', $otherUrl, $body + ['gateway' => '192.0.2.1', '_token' => $otherToken]);
        self::assertResponseRedirects($otherUrl, 303);
        $crawler = $client->followRedirect();
        self::assertStringContainsString('This range overlaps another enabled IP pool.', $crawler->filterXPath('//*[@role="alert"]')->text());

        $crawler = $client->request('GET', $base.'/guests/qemu/900');
        self::assertResponseIsSuccessful();
        self::assertSame('Static addresses from pool (IPv4)', $crawler->filterXPath('//select[@name="address_mode"]/option[1][@value="pool"]')->text());
        self::assertCount(1, $crawler->filterXPath('//select[@name="address_mode"]/option[@value="dhcp"]'));
        self::assertSame([], $this->fixture->writes());

        self::ensureKernelShutdown();
        $client = $this->client(false);
        $client->request('GET', $url);
        self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);
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

    public function testCustomerCanSeeAssignedIpsAndResetOnlyTheRootPasswordOfTheOwnedVm(): void
    {
        $client = $this->client(false);
        $this->fixture->guests[0]['status'] = 'running';
        $this->fixture->configs[101] = ['agent' => 'enabled=1', 'ipconfig0' => 'ip=192.0.2.20/24,gw=192.0.2.1,ip6=2001:db8::20/64'];
        $url = '/customer/proxmox/'.$this->ownId.'/root-password';
        $page = $client->request('GET', '/customer/proxmox/'.$this->ownId);
        self::assertResponseIsSuccessful();
        self::assertSame(['192.0.2.20', '2001:db8::20'], $page->filterXPath('//*[@id="pve-access"]//code')->each(static fn ($code): string => $code->text()));
        $token = $page->filterXPath('//form[@action="'.$url.'"]//input[@name="_token"]')->attr('value');

        $client->request('POST', $url, ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->fixture->writes());
        $password = 'Customer-approved_2026!';
        $guestUrl = '/customer/proxmox/'.$this->ownId;
        $client->request('POST', $url, ['_token' => $token, 'confirm' => '1', 'password' => $password, 'password_confirm' => 'mismatch']);
        self::assertResponseRedirects($guestUrl.'#pve-access', 303);
        self::assertSame([], $this->fixture->writes());
        $client->request('POST', $url, ['_token' => $token, 'confirm' => '1', 'username' => 'other', 'vm_id' => '999', 'password' => $password, 'password_confirm' => $password]);
        self::assertResponseRedirects($guestUrl.'#pve-access', 303);
        self::assertStringNotContainsString($password, (string) $client->getResponse()->getContent());
        $reveal = $url.'/reveal';
        $client->request('POST', '/customer/proxmox/'.$this->otherId.'/root-password/reveal', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        $result = $client->request('POST', $reveal, ['_token' => $token]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame($password, $result->filterXPath('//input[@type="text" and @readonly]')->attr('value'));
        $client->request('POST', $reveal, ['_token' => $token]);
        self::assertResponseRedirects($guestUrl.'#pve-access', 303);
        $writes = $this->fixture->writes();
        self::assertCount(1, $writes);
        self::assertSame('/nodes/pve-a/qemu/101/agent/set-user-password', $writes[0]['path']);
        self::assertSame('root', $writes[0]['body']['username']);
        self::assertSame('1', $writes[0]['body']['crypted']);
        self::assertSame($writes[0]['body']['password'], crypt($password, $writes[0]['body']['password']));
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
        self::getContainer()->set(\App\Module\Nodes\Application\Proxmox\ProxmoxRrdUsage::class, new \App\Module\Nodes\Application\Proxmox\ProxmoxRrdUsage($this->fixture->client(), new \Symfony\Component\Cache\Adapter\ArrayAdapter()));
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

    public function testCustomerCanRestoreAnOwnedProxmoxBackupFromThePanel(): void
    {
        $client = $this->client(false);
        $this->fixture->snapshots = [['name' => 'current']];
        $volume = 'local:backup/vzdump-qemu-101-2026_11_12.vma.zst';
        $this->fixture->configs[101] = ['memory' => 2048, 'scsi0' => 'local-lvm:vm-101-disk-0,size=32G'];
        $this->fixture->backupConfigurations[$volume] = "# Backup\ntags: easywi-owned-0123456789abcdef\nmemory: 2048\nscsi0: local-lvm:vm-101-disk-0,size=32G";
        $url = '/customer/proxmox/'.$this->ownId;
        $page = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $selector = '//form[input[@name="action" and @value="backup-restore"]]';
        self::assertCount(1, $page->filterXPath($selector.'//button[not(@disabled)]'));
        self::assertCount(0, $page->filterXPath($selector.'//*[@name="target_storage"]'));
        self::assertCount(0, $page->filterXPath('//input[@name="action" and @value="backup-delete"]'));
        $form = $page->filterXPath($selector)->form(['confirm' => '1']);
        $payload = $form->getPhpValues();
        $client->request('POST', $url.'/action', array_replace($payload, ['_token' => 'invalid']));
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $url.'/action', array_replace($payload, ['confirm' => '']));
        self::assertResponseRedirects($url.'#pve-backups', 303);
        self::assertSame([], $this->fixture->writes());
        $client->submit($form);
        self::assertResponseRedirects($url.'#pve-activity', 303);
        self::assertSame('/nodes/pve-a/qemu', $this->fixture->writes()[0]['path']);
        self::assertSame(['vmid' => '101', 'start' => '0', 'force' => '1', 'archive' => $volume, 'unique' => '0'], $this->fixture->writes()[0]['body']);
        $task = self::getContainer()->get(EntityManagerInterface::class)->getRepository(ProxmoxTask::class)->findOneBy(['requestKey' => $payload['request_key']]);
        unset($this->fixture->guests[0]);
        $page = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filterXPath('//*[@id="pve-activity"]//*[@data-pve-task-url]'));
        $this->fixture->complete = true;
        $client->request('GET', $url.'/tasks/'.$task->getId());
        self::assertResponseIsSuccessful();
        self::assertSame('succeeded', json_decode((string) $client->getResponse()->getContent(), true)['state']);
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
