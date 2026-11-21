<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus;
use App\Module\Core\Application\AgentSignatureVerifier;
use App\Module\Core\Application\EncryptionServiceInterface;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\AuditLog;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Enum\JobStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\WithEnvironmentVariable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

final class KvmHttpTest extends AbstractWebTestCase
{
    private const NODE = 'kvm-http-node';
    private const VM = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement("DELETE FROM agent_jobs WHERE type = 'kvm.managed'");
        $node = new Agent(self::NODE, ['key_id' => 'v1', 'nonce' => 'synthetic', 'ciphertext' => 'synthetic'], 'KVM test host');
        $node->recordHeartbeat([], 'test', '127.0.0.1', [], ['kvm_managed' => ['version' => 1, 'supported' => true, 'acceleration' => true, 'missing_tools' => []]], Agent::STATUS_ACTIVE);
        $em->persist($node);
        $em->flush();
        self::ensureKernelShutdown();
    }

    public function testKvmCanBeDisabledIndependentlyThroughModuleSettings(): void
    {
        $client = $this->client();
        $crawler = $client->request('GET', '/admin/modules');
        self::assertResponseIsSuccessful();
        $token = $crawler->filterXPath('//form[@action="/admin/modules/kvm/toggle"]//input[@name="_token"]')->attr('value');
        $client->request('POST', '/admin/modules/kvm/toggle', ['_token' => $token, 'enabled' => '0']);
        self::assertResponseStatusCodeSame(302);
        try {
            $client->request('GET', '/admin/kvm');
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/admin/proxmox');
            self::assertResponseIsSuccessful();
        } finally {
            $client->request('POST', '/admin/modules/kvm/toggle', ['_token' => $token, 'enabled' => '1']);
        }
        $client->request('GET', '/admin/kvm');
        self::assertResponseIsSuccessful();
    }

    #[WithEnvironmentVariable('APP_ENCRYPTION_KEYS', 'v1:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ=')]
    #[WithEnvironmentVariable('ACTIVE_ENCRYPTION_KEY_ID', 'v1')]
    public function testHostSetupRequiresAssignedRoleAndConfirmation(): void
    {
        $client = $this->client();
        $body = $this->body($client, 'host.setup');
        $client->xmlHttpRequest('POST', $this->actionUrl(), $body + ['confirm' => '1']);
        self::assertResponseStatusCodeSame(422);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $node = $em->find(Agent::class, self::NODE);
        self::assertNotNull($node);
        $metadata = $node->getMetadata();
        $metadata['kvm_managed']['host_setup_version'] = 1;
        $node->setMetadata($metadata);
        $em->flush();
        $client->xmlHttpRequest('POST', $this->actionUrl(), $body + ['confirm' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('kvm.role_required', json_decode($client->getResponse()->getContent(), true)['error']);
        self::assertSame(0, $this->countJobs());
        $rolesUrl = '/admin/nodes/'.self::NODE.'/roles';
        $view = $client->request('GET', $rolesUrl);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $view->filterXPath('//input[@name="roles[]" and @value="KVM" and not(@checked)]'));
        $token = $view->filterXPath('//form[@action="'.$rolesUrl.'"]//input[@name="_token"]')->attr('value');
        $client->request('POST', $rolesUrl, ['roles' => ['KVM']]);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $rolesUrl, ['roles' => ['KVM'], '_token' => $token]);
        self::assertResponseIsSuccessful();
        self::assertSame(['KVM'], $em->find(Agent::class, self::NODE)->getRoles());
        $roleJobs = array_filter($em->getRepository(Job::class)->findBy(['type' => 'role.ensure_base']), static fn (Job $job): bool => ($job->getPayload()['agent_id'] ?? null) === self::NODE);
        self::assertCount(1, $roleJobs);
        self::assertSame('KVM', array_values($roleJobs)[0]->getPayload()['role']);
        $client->xmlHttpRequest('POST', $this->actionUrl(), $body);
        self::assertResponseStatusCodeSame(422);
        $client->xmlHttpRequest('POST', $this->actionUrl(), $body + ['confirm' => '1', 'packages' => 'untrusted']);
        self::assertResponseStatusCodeSame(202);
        self::assertSame(['action' => 'host.setup', 'operation_id' => $body['operation_id'], 'confirm' => '1', 'role' => 'KVM'], $this->latest()->getPayload());

        $secret = 'kvm-test-secret-only';
        $node = $em->find(Agent::class, self::NODE);
        $node->setSecretPayload(self::getContainer()->get(EncryptionServiceInterface::class)->encrypt($secret));
        $em->flush();
        $jobId = $this->latest()->getId();
        $client->request('POST', $rolesUrl, ['_token' => $token]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $em->find(Agent::class, self::NODE)->getRoles());
        $start = '/agent/'.self::NODE.'/jobs/'.$jobId.'/start';
        $client->request('POST', $start, server: $this->agentHeaders($secret, 'POST', $start));
        self::assertResponseStatusCodeSame(403);
        self::assertSame('kvm_role_required', json_decode($client->getResponse()->getContent(), true)['error']);
        self::assertSame(AgentJobStatus::Failed, $em->find(AgentJob::class, $jobId)->getStatus());

        $client->request('GET', '/agent/jobs', server: $this->agentHeaders($secret, 'GET', '/agent/jobs'));
        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode($client->getResponse()->getContent(), true)['jobs']);
        self::assertSame(JobStatus::Cancelled, $em->find(Job::class, array_values($roleJobs)[0]->getId())->getStatus());
    }

    public function testDiskExpansionRequiresCapabilityAndDispatchesOnlyApprovedFields(): void
    {
        $client = $this->client();
        $body = $this->body($client, 'vm.disk_grow') + ['vm_id' => self::VM, 'disk_gib' => '40', 'expected_disk_gib' => '20', 'confirm' => '1', 'path' => '/dev/sda', 'size' => '+20G'];
        $client->xmlHttpRequest('POST', $this->actionUrl(), $body);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->countJobs());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $node = $em->find(Agent::class, self::NODE);
        $meta = $node->getMetadata(); $meta['kvm_managed']['disk_grow_version'] = 1; $node->setMetadata($meta); $em->flush();
        foreach ([['confirm' => ''], ['disk_gib' => '20'], ['disk_gib' => '2049'], ['disk_gib' => '40.5'], ['expected_disk_gib' => '0']] as $change) {
            $client->xmlHttpRequest('POST', $this->actionUrl(), array_replace($body, $change));
            self::assertResponseStatusCodeSame(422);
            self::assertSame(0, $this->countJobs());
        }
        $client->xmlHttpRequest('POST', $this->actionUrl(), $body);
        self::assertResponseStatusCodeSame(202);
        self::assertSame(['action' => 'vm.disk_grow', 'operation_id' => $body['operation_id'], 'vm_id' => self::VM, 'disk_gib' => 40, 'expected_disk_gib' => 20, 'confirm' => '1'], $this->latest()->getPayload());
        $client->xmlHttpRequest('POST', $this->actionUrl(), $body);
        self::assertResponseStatusCodeSame(202);
        self::assertSame(1, $this->countJobs());
    }

    public function testNetworkChangesRequireANewAgentAndOnlyDispatchStructuredFields(): void
    {
        $client = $this->client();
        $body = $this->body($client, 'vm.network') + ['vm_id' => self::VM, 'network_choice' => 'network:isolated', 'expected_network_type' => 'network', 'expected_network' => 'default', 'confirm' => '1', 'domain_xml' => '<unsafe/>', 'ip' => '203.0.113.1'];
        $client->xmlHttpRequest('POST', $this->actionUrl(), $body);
        self::assertResponseStatusCodeSame(422);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $node = $em->find(Agent::class, self::NODE);
        $metadata = $node->getMetadata(); $metadata['kvm_managed']['network_config_version'] = 1; $node->setMetadata($metadata); $em->flush();
        $client->xmlHttpRequest('POST', $this->actionUrl(), array_replace($body, ['confirm' => '']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->countJobs());
        $client->xmlHttpRequest('POST', $this->actionUrl(), $body);
        self::assertResponseStatusCodeSame(202);
        self::assertSame(['action' => 'vm.network', 'operation_id' => $body['operation_id'], 'vm_id' => self::VM, 'network_type' => 'network', 'expected_network_type' => 'network', 'network' => 'isolated', 'expected_network' => 'default', 'confirm' => '1'], $this->latest()->getPayload());
        $client->xmlHttpRequest('POST', $this->actionUrl(), $body);
        self::assertResponseStatusCodeSame(202);
        self::assertSame(1, $this->countJobs());
    }

    public function testDiskGrowControlsExplainSnapshotAndAgentRequirements(): void
    {
        $client = $this->client();
        $client->request('POST', $this->actionUrl(), $this->body($client, 'inspect'));
        $this->finish($this->latest(), ['inventory' => $this->inventory()]);
        $url = $this->nodeUrl().'/guests/'.self::VM;
        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filterXPath('//*[@data-vm-disk-grow]//form'));
        self::assertStringContainsString('Agent', $crawler->filterXPath('//*[@data-vm-disk-grow]')->text());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $node = $em->find(Agent::class, self::NODE);
        $meta = $node->getMetadata(); $meta['kvm_managed']['disk_grow_version'] = 1; $node->setMetadata($meta); $em->flush();
        $crawler = $client->request('GET', $url);
        self::assertCount(1, $crawler->filterXPath('//*[@data-vm-disk-grow]//button[@disabled]'));
        self::assertSame('21', $crawler->filterXPath('//*[@data-vm-disk-grow]//input[@name="disk_gib"]')->attr('min'));
        self::assertStringContainsString('Snapshots', $crawler->filterXPath('//*[@data-vm-disk-grow]')->text());
    }

    public function testAdminCanInspectAndSeeManagedResourcesWithoutEscapingFailure(): void
    {
        $client = $this->client();
        $client->request('GET', '/admin/kvm');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('KVM test host', (string) $client->getResponse()->getContent());
        $body = $this->body($client, 'inspect');
        $client->request('POST', $this->actionUrl(), $body);
        self::assertResponseStatusCodeSame(303);
        $job = $this->latest();
        self::assertSame(['action' => 'inspect'], $job->getPayload());
        $this->finish($job, ['inventory' => $this->inventory()]);
        $client->request('GET', $this->nodeUrl());
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Customer &lt;VM&gt;', $html);
        self::assertStringNotContainsString('Customer <VM>', $html);
        foreach (['ubuntu', '24.04-v1', 'easywi-guests', '/guests/'.self::VM] as $text) {
            self::assertStringContainsString($text, $html);
        }
        self::assertStringContainsString('src="/js/kvm.js', $html);
        $client->request('GET', $this->nodeUrl().'/guests/'.self::VM);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        foreach (['before-update', 'backup.restore', 'vm.limits', 'data-infra-usage-url', 'data-infra-tab'] as $text) {
            self::assertStringContainsString($text, $html);
        }
        self::assertStringContainsString('data-series-role="node"', $html);
    }

    public function testMutationsRequireCsrfAndAreIdempotentEvenAfterCompletion(): void
    {
        $client = $this->client();
        $body = $this->body($client, 'vm.start') + ['vm_id' => self::VM];
        $invalid = $body;
        $invalid['_token'] = 'invalid';
        $client->request('POST', $this->actionUrl(), $invalid);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->countJobs());
        $client->request('POST', $this->actionUrl(), $body);
        self::assertResponseStatusCodeSame(303);
        $job = $this->latest();
        $id = $job->getId();
        $this->finish($job, ['operation_id' => $body['operation_id'], 'action' => 'vm.start']);
        $client->request('POST', $this->actionUrl(), $body);
        self::assertResponseStatusCodeSame(303);
        self::assertSame(1, $this->countJobs());
        self::assertSame($id, $this->latest()->getId());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertNotEmpty($em->getRepository(AuditLog::class)->findBy(['action' => 'kvm.job_requested']));
    }

    public function testPendingOperationBlocksConflictingRequests(): void
    {
        $client = $this->client();
        $client->request('POST', $this->actionUrl(), $this->body($client, 'vm.start') + ['vm_id' => self::VM]);
        $client->request('POST', $this->actionUrl(), $this->body($client, 'vm.stop') + ['vm_id' => self::VM]);
        self::assertResponseStatusCodeSame(303);
        self::assertSame(1, $this->countJobs());
        $client->followRedirect();
        self::assertStringContainsString('KVM', strip_tags((string) $client->getResponse()->getContent()));
    }

    public function testCustomerCannotViewOrSubmitOrPollHostJobs(): void
    {
        $client = $this->client(false);
        foreach ([$this->nodeUrl(), '/admin/kvm', $this->nodeUrl().'/usage', $this->nodeUrl().'/guests/'.self::VM, $this->nodeUrl().'/guests/'.self::VM.'/usage', $this->nodeUrl().'/jobs/'.Uuid::v4()] as $url) {
            $client->request('GET', $url);
            self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);
        }
        $client->request('POST', $this->actionUrl(), ['action' => 'inspect']);
        self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);
        self::assertSame(0, $this->countJobs());
    }

    public function testJobPollingIsBoundToNodeAndJobType(): void
    {
        $client = $this->client();
        $client->request('POST', $this->actionUrl(), $this->body($client, 'inspect'));
        $job = $this->latest();
        $client->request('GET', $this->nodeUrl().'/jobs/'.$job->getId());
        self::assertResponseIsSuccessful();
        self::assertSame('queued', json_decode((string) $client->getResponse()->getContent(), true)['status']);
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $foreign = new Agent('kvm-foreign-node', ['key_id' => 'v1', 'nonce' => 'n', 'ciphertext' => 'c']);
        $em->persist($foreign);
        $wrong = new AgentJob(Uuid::v4()->toRfc4122(), $foreign, 'kvm.managed', ['action' => 'inspect']);
        $otherType = new AgentJob(Uuid::v4()->toRfc4122(), $em->find(Agent::class, self::NODE), 'kvm.vm.list', []);
        $em->persist($wrong);
        $em->persist($otherType);
        $em->flush();
        foreach ([$wrong->getId(), $otherType->getId()] as $id) {
            $client->request('GET', $this->nodeUrl().'/jobs/'.$id);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testOldAgentCannotReceiveManagedJobs(): void
    {
        $client = $this->client();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->find(Agent::class, self::NODE)->setMetadata([]);
        $em->flush();
        $client->request('POST', $this->actionUrl(), $this->body($client, 'inspect'));
        self::assertResponseStatusCodeSame(303);
        self::assertSame(0, $this->countJobs());
        $client->followRedirect();
        self::assertStringContainsString('Agent', strip_tags((string) $client->getResponse()->getContent()));
    }

    public function testFailedInspectionKeepsPreviousInventory(): void
    {
        $client = $this->client();
        $client->request('POST', $this->actionUrl(), $this->body($client, 'inspect'));
        $this->finish($this->latest(), ['inventory' => $this->inventory()]);
        $client->request('POST', $this->actionUrl(), $this->body($client, 'inspect'));
        $job = $this->latest();
        $job->setErrorText('synthetic inspection failure');
        $job->markFinished(AgentJobStatus::Failed);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->request('GET', $this->nodeUrl());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Customer &lt;VM&gt;', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('synthetic inspection failure', (string) $client->getResponse()->getContent());
        $client->request('GET', $this->nodeUrl().'/guests/'.self::VM);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('before-update', (string) $client->getResponse()->getContent());
    }

    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidInputsBeforeDispatch(string $action, array $extra): void
    {
        $client = $this->client();
        $client->request('POST', $this->actionUrl(), $this->body($client, $action) + $extra, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->countJobs());
    }

    public static function invalidInputs(): iterable
    {
        yield 'unknown action' => ['vm.shell', ['vm_id' => self::VM]];
        yield 'path traversal' => ['vm.start', ['vm_id' => '../../etc/shadow']];
        yield 'invalid CPU' => ['vm.limits', ['vm_id' => self::VM, 'vcpus' => 0, 'memory_mib' => 2048]];
        yield 'fractional memory' => ['vm.limits', ['vm_id' => self::VM, 'vcpus' => 2, 'memory_mib' => '512.5']];
        yield 'snapshot injection' => ['snapshot.create', ['vm_id' => self::VM, 'snapshot' => '--help']];
        yield 'foreign bridge name' => ['network.start', ['network' => 'br0']];
        yield 'backup traversal' => ['backup.restore', ['vm_id' => self::VM, 'backup_id' => '../../etc']];
        yield 'insecure download' => ['template.import', ['template_id' => 'ubuntu', 'version' => '24.04', 'sha256' => str_repeat('a', 64), 'url' => 'http://example.org/image.qcow2']];
    }

    public function testCreateBuildsStructuredCloudInitPayloadAndIgnoresArbitraryPaths(): void
    {
        $client = $this->client();
        $body = $this->body($client, 'vm.create') + ['label' => 'Test VM', 'template' => 'ubuntu--24.04-v1', 'hostname' => 'test-vm', 'username' => 'easywi', 'network_choice' => 'network:default', 'disk_gib' => 20, 'vcpus' => 2, 'memory_mib' => 2048, 'ssh_keys' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakePublicKeyOnly', 'disk_path' => '/etc/shadow', 'user_data' => 'runcmd: [evil]'];
        $client->request('POST', $this->actionUrl(), $body, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(202);
        $payload = $this->latest()->getPayload();
        self::assertSame('default', $payload['network']);
        self::assertSame('network', $payload['network_type']);
        self::assertSame(20, $payload['disk_gib']);
        self::assertIsArray($payload['ssh_keys']);
        self::assertArrayNotHasKey('disk_path', $payload);
        self::assertArrayNotHasKey('user_data', $payload);
    }

    public function testStaticAddressPoolsRequireAgentSupportAndAppearInVmCreation(): void
    {
        $client = $this->client();
        $body = $this->body($client, 'host.address_pool');
        $pool = ['network_type' => 'network', 'network' => 'easywi-guests', 'family' => 6, 'first' => '2001:db8::10', 'last' => '2001:db8::19', 'gateway' => 'fe80::1', 'dns' => '2001:db8::1', 'prefix' => 64];
        $fields = ['network_choice' => 'network:easywi-guests'] + array_diff_key($pool, array_flip(['network_type', 'network']));
        $client->xmlHttpRequest('POST', $this->actionUrl(), $body + $fields);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->countJobs());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $node = $em->find(Agent::class, self::NODE);
        self::assertNotNull($node);
        $node->recordHeartbeat([], 'test', '127.0.0.1', [], ['kvm_managed' => ['version' => 1, 'supported' => true, 'acceleration' => true, 'address_pool_version' => 1]], Agent::STATUS_ACTIVE);
        $em->flush();
        $client->xmlHttpRequest('POST', $this->actionUrl(), $body + ['dns' => '192.0.2.1'] + $fields);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->countJobs());
        $client->xmlHttpRequest('POST', $this->actionUrl(), $body + $fields + ['raw_network_config' => 'unused']);
        self::assertResponseStatusCodeSame(202);
        $job = $this->latest();
        self::assertEquals(['action' => 'host.address_pool', 'operation_id' => $body['operation_id']] + $pool, $job->getPayload());
        $this->finish($job, ['inventory' => $this->inventory() + ['address_pools' => [$pool]]]);
        $crawler = $client->request('GET', $this->nodeUrl());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filterXPath('//*[@data-kvm-address-pools]//input[@name="remove"]'));
        self::assertCount(1, $crawler->filterXPath('//select[@name="address_mode"]/option[@value="pool"]'));

        $create = $this->body($client, 'vm.create') + ['label' => 'Static VM', 'template' => 'ubuntu--24.04-v1', 'hostname' => 'test-vm', 'username' => 'easywi', 'network_choice' => 'network:easywi-guests', 'disk_gib' => 20, 'vcpus' => 2, 'memory_mib' => 2048, 'ssh_keys' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakePublicKeyOnly', 'address_mode' => 'pool'];
        $client->xmlHttpRequest('POST', $this->actionUrl(), $create);
        self::assertResponseStatusCodeSame(202);
        $result = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $createJob = $em->find(AgentJob::class, $result['job_id']);
        self::assertNotNull($createJob);
        self::assertSame('pool', $createJob->getPayload()['address_mode'] ?? null);
        self::assertArrayNotHasKey('gateway', $createJob->getPayload());
    }

    /** @return array<string, string> */
    private function agentHeaders(string $secret, string $method, string $path): array
    {
        $nonce = bin2hex(random_bytes(16));
        $now = time();
        $timestamp = gmdate(DATE_RFC3339, $now);
        $signature = hash_hmac('sha256', AgentSignatureVerifier::buildSignaturePayload(self::NODE, $method, $path, $timestamp, $nonce, ''), $secret);
        $base64 = static fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        $container = self::getContainer();
        $claims = ['iss' => $container->getParameter('app.agent_jwt_issuer'), 'aud' => $container->getParameter('app.agent_jwt_audience'), 'sub' => self::NODE, 'iat' => $now, 'exp' => $now + 60, 'jti' => $nonce];
        $token = $base64('{"alg":"HS256"}').'.'.$base64(json_encode($claims, JSON_THROW_ON_ERROR));
        $token .= '.'.$base64(hash_hmac('sha256', $token, $secret, true));

        return ['HTTP_X_AGENT_ID' => self::NODE, 'HTTP_X_TIMESTAMP' => $timestamp, 'HTTP_X_NONCE' => $nonce, 'HTTP_X_SIGNATURE' => $signature, 'HTTP_AUTHORIZATION' => 'Bearer '.$token];
    }

    private function client(bool $admin = true): KernelBrowser
    {
        $client = static::createClient();
        $this->loginAsRole($client, $admin ? 'admin' : 'customer');
        $client->disableReboot();

        return $client;
    }

    private function body(KernelBrowser $client, string $action): array
    {
        $client->request('GET', $this->nodeUrl());
        self::assertResponseIsSuccessful();

        preg_match('/name="_token" value="([^"]+)"/', (string) $client->getResponse()->getContent(), $matches);
        self::assertNotEmpty($matches[1] ?? null);

        return ['action' => $action, 'operation_id' => Uuid::v4()->toRfc4122(), '_token' => html_entity_decode($matches[1], ENT_QUOTES)];
    }

    private function latest(): AgentJob
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(AgentJob::class)->findOneBy(['type' => 'kvm.managed', 'node' => self::NODE], ['createdAt' => 'DESC']);
    }

    private function countJobs(): int
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(AgentJob::class)->count(['type' => 'kvm.managed', 'node' => self::NODE]);
    }

    private function finish(AgentJob $job, array $result): void
    {
        $job->setResultPayload($result);
        $job->markFinished(AgentJobStatus::Success);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function nodeUrl(): string
    {
        return '/admin/kvm/nodes/'.self::NODE;
    }

    private function actionUrl(): string
    {
        return $this->nodeUrl().'/actions';
    }

    private function inventory(): array
    {
        return ['observed_at' => '2026-11-12T12:00:00Z', 'capabilities' => ['acceleration' => true, 'missing_tools' => []], 'templates' => [['id' => 'ubuntu', 'version' => '24.04-v1', 'virtual_size' => 1 << 30, 'sha256' => str_repeat('a', 64)]], 'bridges' => [], 'networks' => [['name' => 'easywi-guests', 'managed' => true, 'details' => ['Active' => 'yes', 'Bridge' => 'virbr9']]], 'guests' => [['id' => self::VM, 'label' => 'Customer <VM>', 'managed' => true, 'state' => 'shut off', 'vcpus' => 2, 'memory_mib' => 2048, 'disk_gib' => 20, 'network' => 'easywi-guests', 'network_type' => 'network', 'hostname' => 'test-vm', 'username' => 'easywi', 'template' => 'ubuntu--24.04-v1', 'snapshots' => ['before-update'], 'backups' => [['id' => '22222222-2222-4222-8222-222222222222', 'created_at' => '2026-11-12T12:00:00Z', 'size' => 1048576]]]]];
    }
}
