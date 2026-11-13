<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\UserSession;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Nodes\Application\Proxmox\ProxmoxApiClient;
use App\Module\Nodes\Domain\Entity\KvmGuestAssignment;
use App\Module\Nodes\Domain\Entity\ProxmoxCluster;
use App\Module\Nodes\Domain\Entity\ProxmoxGuestAssignment;
use App\Module\Nodes\Domain\Entity\VmConsoleSession;
use App\Module\Nodes\Domain\Proxmox\ProxmoxConnection;
use App\Tests\Support\ProxmoxFixture;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

final class VmConsoleHttpTest extends AbstractWebTestCase
{
    private const SECRET = 'synthetic-console-gateway-secret-for-tests-only';
    private const VM = '11111111-1111-4111-8111-111111111111';
    private int $pveId;
    private int $kvmId;
    private int $foreignId;
    private ProxmoxFixture $fixture;
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['APP_ENCRYPTION_KEYS' => 'v1:'.base64_encode(str_repeat('t', 32)), 'ACTIVE_ENCRYPTION_KEY_ID' => 'v1'] as $key => $value) {
            $this->savedEnv[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        $this->seedSite();
        self::bootKernel();
        $instance = $this->seedInstance();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $cluster = new ProxmoxCluster('private-cluster', new ProxmoxConnection('https://pve.example.test:8006', 'panel@pve!test', 'synthetic-pve-secret'), self::getContainer()->get(SecretsCrypto::class));
        $other = new User('other-vnc@example.test', UserType::Customer);
        $other->setPasswordHash('unused');
        $own = new ProxmoxGuestAssignment($cluster, 101, 'qemu', $instance->getCustomer(), 'easywi-owned-0123456789abcdef');
        $foreign = new ProxmoxGuestAssignment($cluster, 102, 'lxc', $other, 'easywi-owned-fedcba9876543210');
        $node = $instance->getNode();
        $node->setSecretPayload(self::getContainer()->get(EncryptionService::class)->encrypt('synthetic-agent-secret'));
        $node->setServiceBaseUrl('https://private-agent.example.test:7456');
        $node->recordHeartbeat([], 'test', '127.0.0.1', [], ['kvm_managed' => ['version' => 1, 'console_version' => 1, 'supported' => true]], 'active');
        $kvm = new KvmGuestAssignment($node, self::VM, $instance->getCustomer());
        $job = new AgentJob(Uuid::v4()->toRfc4122(), $node, 'kvm.managed', ['action' => 'inspect']);
        $job->setResultPayload(['inventory' => ['guests' => [['id' => self::VM, 'managed' => true, 'label' => 'Customer KVM', 'state' => 'running', 'vcpus' => 2, 'memory_mib' => 2048, 'disk_gib' => 20, 'console_enabled' => true, 'network' => 'private-host-bridge', 'error' => '/srv/secret-host-path']]]]);
        $job->markFinished(AgentJobStatus::Success);
        foreach ([$cluster, $other, $own, $foreign, $kvm, $job] as $entity) {
            $em->persist($entity);
        }$em->flush();
        $this->pveId = $own->getId();
        $this->kvmId = $kvm->getId();
        $this->foreignId = $foreign->getId();
        $this->fixture = new ProxmoxFixture();
        $this->fixture->guests[0]['status'] = 'running';
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->savedEnv as $key => [$env, $server]) {
            if (null === $env) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
            if (null === $server) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
        }
    }

    public function testCustomersSeeOnlyTheirAssignedVmAndConsoleWithoutHostSecrets(): void
    {
        $client = $this->client();
        foreach (['/customer/kvm', '/customer/kvm/'.$this->kvmId, '/customer/proxmox/'.$this->pveId] as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful();
            $html = (string) $client->getResponse()->getContent();
            foreach (['synthetic-agent-secret', 'synthetic-pve-secret', 'private-agent.example', '/srv/secret-host-path', 'private-host-bridge', 'synthetic-console-gateway'] as $secret) {
                self::assertStringNotContainsString($secret, $html);
            }
        }
        self::assertStringContainsString('data-vm-console', $html);
        $client->request('GET', '/customer/proxmox/'.$this->foreignId);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTicketRequiresOwnershipAndCsrf(): void
    {
        $client = $this->client();
        $client->request('POST', $this->ticketUrl('proxmox', $this->foreignId), ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', $this->ticketUrl('proxmox', $this->pveId), ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->em()->getRepository(VmConsoleSession::class)->count([]));
    }

    public function testProxmoxGrantIsSingleUseSessionBoundAndResolvesMigratedNode(): void
    {
        $client = $this->client();
        $grant = $this->mint($client, 'proxmox', $this->pveId);
        self::assertSame(64, strlen($grant['ticket']));
        self::assertSame('/vm-console/ws', $grant['websocket_path']);
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
        $this->fixture->guests[0]['node'] = 'pve-b';
        $this->connect($client, $grant['ticket']);
        self::assertResponseIsSuccessful();
        $target = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('/nodes/pve-b/qemu/101/vncwebsocket?', $target['url']);
        self::assertSame('PVEAPIToken=panel@pve!test=synthetic-pve-secret', $target['authorization']);
        self::assertSame('synthetic-proxmox-vnc-ticket', $target['password']);
        self::assertFalse($target['skip_tls_verify']);
        $this->connect($client, $grant['ticket']);
        self::assertResponseStatusCodeSame(403);
        $writes = array_filter($this->fixture->requests, static fn (array $r): bool => str_ends_with($r['path'], '/vncproxy'));
        self::assertCount(1, $writes);
    }

    public function testProxmoxConsoleUsesTheStoredTlsPreference(): void
    {
        $client = $this->client();
        $cluster = $this->em()->find(ProxmoxGuestAssignment::class, $this->pveId)->getCluster();
        $cluster->configure($cluster->getName(), $cluster->getBaseUrl(), $cluster->getTokenId(), '', self::getContainer()->get(SecretsCrypto::class), false);
        $this->em()->flush();
        $grant = $this->mint($client, 'proxmox', $this->pveId);
        $this->connect($client, $grant['ticket']);
        self::assertResponseIsSuccessful();
        $target = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($target['skip_tls_verify']);
    }

    public function testKvmUsageExposesOnlyMeasurementsForAnOwnedServer(): void
    {
        $client = $this->client();
        $assignment = $this->em()->find(KvmGuestAssignment::class, $this->kvmId);
        $node = $assignment->getNode();
        $node->recordHeartbeat(['metrics' => ['collected_at' => date(DATE_ATOM), 'cpu' => ['percent' => 12.5]], 'kvm_usage' => ['observed_at' => date(DATE_ATOM), 'guests' => [self::VM => ['cpu_percent' => 37.5, 'memory_used_bytes' => 1073741824, 'memory_total_bytes' => 2147483648, 'private' => 'host-secret']]]], 'test', '127.0.0.1', [], $node->getMetadata(), 'active');
        $this->em()->flush();
        $client->request('GET', '/customer/kvm/'.$this->kvmId.'/usage');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
        $html = json_decode((string) $client->getResponse()->getContent(), true)['html'];
        self::assertStringContainsString('37.5 %', $html);
        self::assertStringContainsString('12.5 %', $html);
        foreach (['host-secret', 'private-agent.example', '127.0.0.1', 'synthetic-agent-secret'] as $secret) {
            self::assertStringNotContainsString($secret, $html);
        }
        $client->request('GET', '/customer/kvm/999999/usage');
        self::assertResponseStatusCodeSame(404);
    }

    public function testBrowserCannotCallGatewayCredentialEndpoint(): void
    {
        $client = $this->client();
        $grant = $this->mint($client, 'proxmox', $this->pveId);
        $client->jsonRequest('POST', '/customer/vm-console/connect', ['ticket' => $grant['ticket']]);
        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('synthetic-pve-secret', (string) $client->getResponse()->getContent());
    }

    public function testExpiredGrantAndReplacementGuestAreRejected(): void
    {
        $client = $this->client();
        $grant = $this->mint($client, 'proxmox', $this->pveId);
        $this->em()->getConnection()->executeStatement('UPDATE vm_console_sessions SET connect_before = ?', ['2000-01-01 00:00:00']);
        $this->em()->clear();
        $this->connect($client, $grant['ticket']);
        self::assertResponseStatusCodeSame(403);
        $grant = $this->mint($client, 'proxmox', $this->pveId);
        $this->fixture->guests[0]['tags'] = 'unrelated-replacement';
        $this->connect($client, $grant['ticket']);
        self::assertResponseStatusCodeSame(503);
    }

    public function testKvmTargetUsesOnlyAssignedUuidAndNeverCallerSuppliedAddress(): void
    {
        $client = $this->client();
        $grant = $this->mint($client, 'kvm', $this->kvmId);
        $client->jsonRequest('POST', '/customer/vm-console/connect', ['ticket' => $grant['ticket'], 'url' => 'ws://127.0.0.1:22', 'vm_id' => 'foreign-vm'], ['HTTP_X_EASYWI_CONSOLE_GATEWAY' => self::SECRET]);
        self::assertResponseIsSuccessful();
        $target = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('wss://private-agent.example.test:7456/v1/kvm/console/'.self::VM, $target['url']);
        self::assertSame('Bearer synthetic-agent-secret', $target['authorization']);
        self::assertSame('', $target['password']);
        $client->jsonRequest('POST', '/customer/vm-console/validate', ['session_id' => $target['session_id']], ['HTTP_X_EASYWI_CONSOLE_GATEWAY' => self::SECRET]);
        self::assertResponseIsSuccessful();
        $assignment = $this->em()->find(KvmGuestAssignment::class, $this->kvmId);
        $this->em()->remove($assignment);
        $this->em()->flush();
        $client->jsonRequest('POST', '/customer/vm-console/validate', ['session_id' => $target['session_id']], ['HTTP_X_EASYWI_CONSOLE_GATEWAY' => self::SECRET]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testGrantCannotBeRedeemedInAnotherSessionOfSameCustomer(): void
    {
        $client = $this->client();
        $grant = $this->mint($client, 'kvm', $this->kvmId);
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'instance-customer@example.test']);
        $session = new UserSession($user, hash('sha256', 'different-session-token'));
        $this->em()->persist($session);
        $this->em()->flush();
        $client->getCookieJar()->clear();
        $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('easywi_customer_session', 'different-session-token'));
        $this->connect($client, $grant['ticket']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testRevokedLoginCannotValidateExistingConsole(): void
    {
        $client = $this->client();
        $grant = $this->mint($client, 'kvm', $this->kvmId);
        $this->connect($client, $grant['ticket']);
        $id = json_decode((string) $client->getResponse()->getContent(), true)['session_id'];
        foreach ($this->em()->getRepository(UserSession::class)->findAll() as $session) {
            $session->revoke();
        }$this->em()->flush();
        $client->jsonRequest('POST', '/customer/vm-console/validate', ['session_id' => $id], ['HTTP_X_EASYWI_CONSOLE_GATEWAY' => self::SECRET, 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseRedirects('/session-expired');
    }

    public function testConsoleAbsoluteExpiryIsEnforced(): void
    {
        $client = $this->client();
        $grant = $this->mint($client, 'kvm', $this->kvmId);
        $this->connect($client, $grant['ticket']);
        $id = json_decode((string) $client->getResponse()->getContent(), true)['session_id'];
        $this->em()->getConnection()->executeStatement('UPDATE vm_console_sessions SET expires_at = ?', ['2000-01-01 00:00:00']);
        $client->jsonRequest('POST', '/customer/vm-console/validate', ['session_id' => $id], ['HTTP_X_EASYWI_CONSOLE_GATEWAY' => self::SECRET]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testForeignKvmAssignmentCannotBeViewedOrOpened(): void
    {
        $client = $this->client();
        $other = $this->em()->getRepository(User::class)->findOneBy(['email' => 'other-vnc@example.test']);
        $assignment = new KvmGuestAssignment($this->em()->getRepository(KvmGuestAssignment::class)->find($this->kvmId)->getNode(), '22222222-2222-4222-8222-222222222222', $other);
        $this->em()->persist($assignment);
        $this->em()->flush();
        $client->request('GET', '/customer/kvm/'.$assignment->getId());
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', $this->ticketUrl('kvm', $assignment->getId()), ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminCanReassignKvmAndEnableCustomerAccess(): void
    {
        $client = static::createClient();
        $this->loginAsRole($client, 'admin');
        $client->disableReboot();
        $old = $this->em()->find(KvmGuestAssignment::class, $this->kvmId);
        $nodeId = $old->getNode()->getId();
        $job = $this->em()->getRepository(AgentJob::class)->findOneBy(['node' => $nodeId, 'type' => 'kvm.managed'], ['createdAt' => 'DESC']);
        $result = $job->getResultPayload();
        unset($result['inventory']['guests'][0]['error']);
        $job->setResultPayload($result);
        $this->em()->flush();
        $other = $this->em()->getRepository(User::class)->findOneBy(['email' => 'other-vnc@example.test']);
        $other->setCustomerAccessEnabled(false);
        $this->em()->flush();
        $url = '/admin/kvm/nodes/'.$nodeId.'/guests/'.self::VM.'/assign';
        $client->request('POST', $url, ['customer_id' => $other->getId(), '_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $token = self::getContainer()->get(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class)->getToken('kvm-assign-'.$nodeId.'-'.self::VM)->getValue();
        $client->request('POST', $url, ['customer_id' => $other->getId(), '_token' => $token]);
        self::assertResponseStatusCodeSame(303);
        $this->em()->clear();
        $new = $this->em()->getRepository(KvmGuestAssignment::class)->findOneBy(['node' => $nodeId, 'vmUuid' => self::VM]);
        self::assertNotSame($this->kvmId, $new->getId());
        self::assertSame($other->getId(), $new->getCustomer()->getId());
        self::assertTrue($new->getCustomer()->isCustomerAccessEnabled());
    }

    public function testGrantIssuanceIsRateLimited(): void
    {
        $client = $this->client();
        for ($i = 0; $i < 5; ++$i) {
            $this->mint($client, 'kvm', $this->kvmId);
        }
        $token = self::getContainer()->get(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class)->getToken('vm-console-kvm-'.$this->kvmId)->getValue();
        $client->request('POST', $this->ticketUrl('kvm', $this->kvmId), ['_token' => $token]);
        self::assertResponseStatusCodeSame(429);
    }

    public function testRemotePlaintextKvmEndpointIsRejected(): void
    {
        $client = $this->client();
        $node = $this->em()->find(KvmGuestAssignment::class, $this->kvmId)->getNode();
        $node->setServiceBaseUrl('http://10.0.0.5:7456');
        $this->em()->flush();
        $grant = $this->mint($client, 'kvm', $this->kvmId);
        $this->connect($client, $grant['ticket']);
        self::assertResponseStatusCodeSame(503);
        self::assertStringNotContainsString('synthetic-agent-secret', (string) $client->getResponse()->getContent());
    }

    public function testFreshProxmoxConfigIdentityOverridesStaleResourceTags(): void
    {
        $client = $this->client();
        $grant = $this->mint($client, 'proxmox', $this->pveId);
        $this->fixture->configTags[101] = 'replacement-VM';
        $this->connect($client, $grant['ticket']);
        self::assertResponseStatusCodeSame(403);
    }

    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->request('GET', '/');
        $client->request('POST', '/login', ['email' => 'instance-customer@example.test', 'password' => 'P@ssw0rd!']);
        $client->disableReboot();
        self::getContainer()->set(ProxmoxApiClient::class, $this->fixture->client());

        return $client;
    }

    private function mint(KernelBrowser $client, string $provider, int $id): array
    {
        $token = self::getContainer()->get(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class)->getToken('vm-console-'.$provider.'-'.$id)->getValue();
        $client->request('POST', $this->ticketUrl($provider, $id), ['_token' => $token]);
        self::assertResponseIsSuccessful();

        return json_decode((string) $client->getResponse()->getContent(), true);
    }

    private function connect(KernelBrowser $client, string $ticket): void
    {
        $client->jsonRequest('POST', '/customer/vm-console/connect', ['ticket' => $ticket], ['HTTP_X_EASYWI_CONSOLE_GATEWAY' => self::SECRET]);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function ticketUrl(string $provider, int $id): string
    {
        return '/customer/vm-console/'.$provider.'/'.$id.'/ticket';
    }
}
