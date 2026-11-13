<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Nodes\Domain\Entity\KvmGuestAssignment;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

final class CustomerKvmManagementHttpTest extends AbstractWebTestCase
{
    private const VM = '11111111-1111-4111-8111-111111111111';
    private const OTHER = '22222222-2222-4222-8222-222222222222';
    private const BACKUP = '33333333-3333-4333-8333-333333333333';
    private int $ownId;
    private int $foreignId;
    private string $inspectionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        self::bootKernel();
        $instance = $this->seedInstance();
        $em = $this->em();
        $em->getConnection()->executeStatement("DELETE FROM agent_jobs WHERE type = 'kvm.managed'");
        $node = $instance->getNode();
        $node->recordHeartbeat([], 'test', '127.0.0.1', [], ['kvm_managed' => ['version' => 1, 'supported' => true, 'console_version' => 1, 'config_version' => 1, 'guest_agent_status_version' => 1]], 'active');
        $other = new User('foreign-kvm@example.test', UserType::Customer);
        $other->setPasswordHash('unused');
        $own = new KvmGuestAssignment($node, self::VM, $instance->getCustomer());
        $foreign = new KvmGuestAssignment($node, self::OTHER, $other);
        $inspection = new AgentJob(Uuid::v4()->toRfc4122(), $node, 'kvm.managed', ['action' => 'inspect']);
        $inspection->setResultPayload(['inventory' => ['guests' => [['id' => self::VM, 'managed' => true, 'state' => 'shut off', 'label' => 'Owned VM', 'vcpus' => 2, 'memory_mib' => 2048, 'disk_gib' => 20, 'snapshots' => ['before-update'], 'backups' => [['id' => self::BACKUP, 'created_at' => date(DATE_ATOM), 'size' => 1024, 'host_path' => '/private/backups']], 'host_path' => '/private/host']]]]);
        $inspection->markFinished(AgentJobStatus::Success);
        foreach ([$other, $own, $foreign, $inspection] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $this->ownId = $own->getId();
        $this->foreignId = $foreign->getId();
        $this->inspectionId = $inspection->getId();
        self::ensureKernelShutdown();
    }

    #[DataProvider('allowedActions')]
    public function testAllowedActionTargetsOnlyTheAssignedVm(string $action, array $extra): void
    {
        $client = $this->client();
        if ('backup.restore' === $action) {
            $this->updateInventory(['snapshots' => []]);
        }
        $body = $this->body($action) + $extra + ['vm_id' => self::OTHER, 'node' => 'foreign-node', 'url' => 'http://foreign.test'];
        $client->request('POST', $this->url().'/action', $body);
        self::assertResponseStatusCodeSame(303);
        $jobs = $this->mutations();
        self::assertCount(1, $jobs);
        $payload = $jobs[0]->getPayload();
        self::assertSame(self::VM, $payload['vm_id']);
        self::assertSame($action, $payload['action']);
        self::assertArrayNotHasKey('url', $payload);
        self::assertArrayNotHasKey('node', $payload);
        $client->request('GET', $this->url().'/jobs/'.$jobs[0]->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
    }

    public static function allowedActions(): iterable
    {
        foreach (['vm.start', 'vm.shutdown', 'vm.stop', 'vm.reboot', 'snapshot.create', 'snapshot.revert', 'snapshot.delete', 'backup.create', 'backup.restore', 'backup.delete'] as $action) {
            yield $action => [$action, ['confirm' => '1', 'snapshot' => str_ends_with($action, '.create') ? 'new-snapshot' : 'before-update', 'backup_id' => self::BACKUP]];
        }
    }

    public function testOwnershipCsrfAndAdminOnlyActionsAreEnforced(): void
    {
        $client = $this->client();
        $body = $this->body('vm.start');
        $client->request('POST', '/customer/kvm/'.$this->foreignId.'/action', $body);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', $this->url().'/action', array_replace($body, ['_token' => 'invalid']));
        self::assertResponseStatusCodeSame(403);
        foreach (['vm.disk_grow', 'vm.network', 'vm.create', 'vm.limits', 'vm.console.configure', 'vm.guest_agent', 'network.create', 'template.import', 'inspect'] as $action) {
            $client->request('POST', $this->url().'/action', array_replace($body, ['action' => $action]));
            self::assertResponseStatusCodeSame(303);
        }
        self::assertCount(0, $this->mutations());
    }

    public function testConfirmationAndCurrentSnapshotBackupMembershipAreRequired(): void
    {
        $client = $this->client();
        foreach (['vm.stop', 'vm.reboot', 'snapshot.revert', 'snapshot.delete', 'backup.restore', 'backup.delete'] as $action) {
            $client->request('POST', $this->url().'/action', $this->body($action) + ['snapshot' => 'before-update', 'backup_id' => self::BACKUP]);
            self::assertResponseStatusCodeSame(303);
        }
        foreach ([['snapshot.delete', ['snapshot' => 'foreign-snapshot']], ['backup.delete', ['backup_id' => self::OTHER]], ['backup.restore', ['backup_id' => self::BACKUP]]] as [$action, $extra]) {
            $client->request('POST', $this->url().'/action', $this->body($action) + $extra + ['confirm' => '1']);
            self::assertResponseStatusCodeSame(303);
        }
        self::assertCount(0, $this->mutations());
        $this->updateInventory(['state' => 'running']);
        $client->request('POST', $this->url().'/action', $this->body('backup.create'));
        self::assertCount(0, $this->mutations());
    }

    public function testRepeatedSubmissionAndPollingDoNotLeakHostJobsOrErrors(): void
    {
        $client = $this->client();
        $body = $this->body('vm.start');
        for ($i = 0; $i < 2; ++$i) {
            $client->request('POST', $this->url().'/action', $body);
            self::assertResponseStatusCodeSame(303);
        }
        $jobs = $this->mutations();
        self::assertCount(1, $jobs);
        $jobs[0]->setErrorText('private host secret /srv/infrastructure');
        $jobs[0]->markFinished(AgentJobStatus::Failed);
        $this->em()->flush();
        $client->request('GET', $this->url().'/jobs/'.$jobs[0]->getId());
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('private host', (string) $client->getResponse()->getContent());
        $client->request('GET', $this->url().'/jobs/'.$this->inspectionId);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/customer/kvm/'.$this->foreignId.'/jobs/'.$jobs[0]->getId());
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $this->url());
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        foreach (['/private/host', '/private/backups', 'private host secret', '/srv/infrastructure'] as $private) {
            self::assertStringNotContainsString($private, $html);
        }
    }

    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->request('GET', '/');
        $client->request('POST', '/login', ['email' => 'instance-customer@example.test', 'password' => 'P@ssw0rd!']);
        $client->disableReboot();

        return $client;
    }

    public function testPendingCustomerJobsPreventUnassignmentUntilTheyFinish(): void
    {
        $client = $this->client();
        $client->request('POST', $this->url().'/action', $this->body('vm.start'));
        self::assertResponseStatusCodeSame(303);
        $job = $this->mutations()[0];
        $assignment = $this->em()->find(KvmGuestAssignment::class, $this->ownId);
        $nodeId = $assignment->getNode()->getId();
        $jobId = $job->getId();
        $client->getCookieJar()->clear();
        $this->loginAsRole($client, 'admin');
        $token = self::getContainer()->get(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class)->getToken('kvm-assign-'.$nodeId.'-'.self::VM)->getValue();
        $url = '/admin/kvm/nodes/'.$nodeId.'/guests/'.self::VM.'/assign';
        $client->request('POST', $url, ['_token' => $token, 'customer_id' => '0']);
        self::assertResponseStatusCodeSame(303);
        self::assertNotNull($this->em()->find(KvmGuestAssignment::class, $this->ownId));
        $this->em()->find(AgentJob::class, $jobId)->markFinished(AgentJobStatus::Success);
        $this->em()->flush();
        self::assertSame('success', $this->em()->getConnection()->fetchOne('SELECT status FROM agent_jobs WHERE id = ?', [$jobId]));
        $client->request('POST', $url, ['_token' => $token, 'customer_id' => '0']);
        self::assertResponseStatusCodeSame(303);
        self::assertNull($this->em()->find(KvmGuestAssignment::class, $this->ownId));
    }

    public function testCustomerOwnershipIsCheckedAgainInsideTheJobTransaction(): void
    {
        $this->client();
        $assignment = $this->em()->find(KvmGuestAssignment::class, $this->ownId);
        $actor = $assignment->getCustomer();
        $other = $this->em()->find(KvmGuestAssignment::class, $this->foreignId)->getCustomer();
        // Bypass ORM hydration to simulate an ownership change after the
        // request's initial entity lookup but before it claims the node lock.
        $this->em()->getConnection()->executeStatement('UPDATE kvm_guest_assignments SET customer_id = ? WHERE id = ?', [$other->getId(), $this->ownId]);
        try {
            self::getContainer()->get(\App\Module\Nodes\Application\Kvm\CustomerKvmService::class)->submit($assignment, $actor, 'vm.start', ['operation_id' => Uuid::v4()->toRfc4122()]);
            self::fail('Stale owner was able to queue a job.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('kvm.customer_forbidden', $error->getMessage());
        }
        self::assertCount(0, $this->mutations());
    }

    public function testCloudInitQueuesOnlyOwnedProvisioningFields(): void
    {
        $client = $this->client();
        $key = \phpseclib3\Crypt\EC::createKey('Ed25519')->getPublicKey()->toString('OpenSSH');
        $this->updateInventory(['cloud_init' => ['hostname' => 'old', 'username' => 'easywi', 'ssh_keys' => [$key], 'private_path' => '/private/cloud']]);
        $client->request('GET', $this->url());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $client->getCrawler()->filterXPath('//*[@id="kvm-settings"]//textarea[@name="ssh_keys"]'));
        self::assertStringNotContainsString('/private/cloud', (string) $client->getResponse()->getContent());
        $client->request('POST', $this->url().'/action', $this->body('vm.cloud_init') + ['hostname' => 'changed-vm', 'ssh_keys' => $key, 'username' => 'root', 'instance_id' => 'forged', 'vm_id' => self::OTHER]);
        self::assertResponseStatusCodeSame(303);
        $jobs = $this->mutations();
        self::assertCount(1, $jobs);
        $payload = $jobs[0]->getPayload();
        self::assertSame(self::VM, $payload['vm_id']);
        self::assertSame('changed-vm', $payload['hostname']);
        self::assertSame([$key], $payload['ssh_keys']);
        self::assertArrayNotHasKey('username', $payload);
        self::assertArrayNotHasKey('instance_id', $payload);
    }

    public function testBootOrderIsValidatedAndRunningVmCannotBeReconfigured(): void
    {
        $client = $this->client();
        foreach (['', 'hd;network', 'network,extra=1'] as $order) {
            $client->request('POST', $this->url().'/action', $this->body('vm.boot_order') + ['boot_order' => $order]);
            self::assertResponseStatusCodeSame(303);
        }
        self::assertCount(0, $this->mutations());
        $this->updateInventory(['state' => 'running']);
        $client->request('POST', $this->url().'/action', $this->body('vm.boot_order') + ['boot_order' => 'network']);
        self::assertCount(0, $this->mutations());
        $this->updateInventory(['state' => 'shut off']);
        $client->request('POST', $this->url().'/action', $this->body('vm.boot_order') + ['boot_order' => 'network']);
        self::assertCount(1, $this->mutations());
        self::assertSame('network', $this->mutations()[0]->getPayload()['boot_order']);
    }

    public function testGuestAgentDiagnosticIsOwnedCoalescedAndSanitized(): void
    {
        $client = $this->client();
        $this->updateInventory(['state' => 'running']);
        for ($i = 0; $i < 2; ++$i) {
            $client->xmlHttpRequest('POST', $this->url().'/action', $this->body('vm.agent_status') + ['vm_id' => self::OTHER, 'command' => 'guest-exec']);
            self::assertResponseStatusCodeSame(202);
        }
        $jobs = $this->mutations();
        self::assertCount(1, $jobs);
        $job = $jobs[0];
        self::assertSame(self::VM, $job->getPayload()['vm_id']);
        self::assertArrayNotHasKey('command', $job->getPayload());
        $job->setResultPayload(['guest_agent' => ['state' => 'reachable', 'observed_at' => date(DATE_ATOM), 'version' => '9.2', 'hostname' => 'actual-guest', 'os' => 'Debian', 'network' => ['state' => 'available', 'interfaces' => [['name' => 'eth0', 'mac' => '52:54:00:12:34:56', 'addresses' => [['family' => 'ipv4', 'address' => '192.0.2.10', 'prefix' => 24, 'host_path' => '/secret']]]]], 'host_path' => '/secret'], 'inventory' => ['foreign_secret' => 'private host']]);
        $job->markFinished(AgentJobStatus::Success);
        $this->em()->flush();
        $client->request('GET', $this->url().'/jobs/'.$job->getId());
        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('actual-guest', $data['guest_agent']['hostname']);
        self::assertSame('192.0.2.10', $data['guest_agent']['network']['interfaces'][0]['addresses'][0]['address']);
        self::assertStringNotContainsString('/secret', $client->getResponse()->getContent());
        self::assertStringNotContainsString('private host', $client->getResponse()->getContent());
        $client->request('GET', '/customer/kvm/'.$this->foreignId.'/jobs/'.$job->getId());
        self::assertResponseStatusCodeSame(404);
    }

    private function body(string $action): array
    {
        $token = self::getContainer()->get(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class)->getToken('kvm-customer-'.$this->ownId)->getValue();

        return ['_token' => $token, 'operation_id' => Uuid::v4()->toRfc4122(), 'action' => $action];
    }

    private function mutations(): array
    {
        return array_values(array_filter($this->em()->getRepository(AgentJob::class)->findBy(['type' => 'kvm.managed']), static fn (AgentJob $job): bool => ($job->getPayload()['action'] ?? '') !== 'inspect'));
    }

    private function updateInventory(array $change): void
    {
        $job = $this->em()->find(AgentJob::class, $this->inspectionId);
        $payload = $job->getResultPayload();
        $payload['inventory']['guests'][0] = array_replace($payload['inventory']['guests'][0], $change);
        $job->setResultPayload($payload);
        $this->em()->flush();
    }

    private function url(): string
    {
        return '/customer/kvm/'.$this->ownId;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
