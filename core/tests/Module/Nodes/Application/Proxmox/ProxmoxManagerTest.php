<?php

declare(strict_types=1);

namespace App\Tests\Module\Nodes\Application\Proxmox;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Nodes\Application\Proxmox\ProxmoxManager;
use App\Module\Nodes\Domain\Entity\ProxmoxCluster;
use App\Module\Nodes\Domain\Entity\ProxmoxGuestAssignment;
use App\Module\Nodes\Domain\Proxmox\ProxmoxConnection;
use App\Tests\Support\ProxmoxFixture;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProxmoxManagerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ProxmoxFixture $fixture;
    private ProxmoxManager $manager;
    private ProxmoxCluster $cluster;
    private User $admin;
    private User $customer;
    private array $environment = [];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        foreach (['DATABASE_URL' => 'sqlite:///:memory:', 'EASYWI_DB_CONFIG_PATH' => '/tmp/proxmox-test-no-config.json'] as $key => $value) {
            $this->environment[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null];
            $_SERVER[$key] = $_ENV[$key] = $value;
        }
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->fixture = new ProxmoxFixture();
        $crypto = self::getContainer()->get(SecretsCrypto::class);
        $this->manager = new ProxmoxManager($this->fixture->client(), $crypto, $this->em, self::getContainer()->get(AuditLogger::class), new \App\Module\Nodes\Application\Proxmox\ProxmoxGuestAgent($this->fixture->client(), new \Symfony\Component\Cache\Adapter\ArrayAdapter()), new \App\Module\Nodes\Application\Proxmox\ProxmoxRrdUsage($this->fixture->client(), new \Symfony\Component\Cache\Adapter\ArrayAdapter()));
        $this->cluster = new ProxmoxCluster('Test cluster', new ProxmoxConnection('https://pve.example.test:8006', 'panel@pve!test', 'synthetic-secret'), $crypto);
        $this->admin = new User('pve-admin@example.test', UserType::Admin);
        $this->admin->setPasswordHash('unused');
        $this->customer = new User('pve-customer@example.test', UserType::Customer);
        $this->customer->setPasswordHash('unused');
        foreach ([$this->cluster, $this->admin, $this->customer] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        foreach ($this->environment as $key => [$server, $env]) {
            if (null === $server) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
            if (null === $env) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
        }
        parent::tearDown();
    }

    public function testDiskExpansionIsAbsoluteTrackedAndIdempotent(): void
    {
        $this->fixture->configs[101] = ['scsi0' => 'local-lvm:vm-101-disk-0,size=32G'];
        $this->fixture->snapshots = [['name' => 'current']];
        $key = bin2hex(random_bytes(16));
        $input = ['disk' => 'scsi0', 'size_gib' => '40', 'expected_digest' => 'fixture-digest', 'expected_bytes' => '34359738368', 'confirm' => '1', 'digest' => 'forged', 'volume' => '/dev/sda'];
        $task = $this->manager->perform($this->cluster, 'qemu', 101, 'disk-grow', $input, $key, $this->admin);
        self::assertSame('succeeded', $task->getState());
        self::assertSame($task, $this->manager->perform($this->cluster, 'qemu', 101, 'disk-grow', $input, $key, $this->admin));
        self::assertCount(1, $this->fixture->writes());
        self::assertSame(['disk' => 'scsi0', 'size' => '40G', 'digest' => 'fixture-digest'], $this->fixture->writes()[0]['body']);
        self::assertSame('/nodes/pve-a/qemu/101/resize', $this->fixture->writes()[0]['path']);
        self::assertSame('PUT', $this->fixture->writes()[0]['method']);
        self::assertSame(42949672960, $this->manager->detail($this->cluster, 'qemu', 101)['disks'][0]['size_bytes']);
    }

    public function testLxcRootDiskExpansionUsesCurrentNodeAndContainerEndpoint(): void
    {
        $this->fixture->guests[1]['status'] = 'stopped';
        $this->fixture->configs[102] = ['rootfs' => 'local-lvm:vm-102-disk-0,size=8G'];
        $this->fixture->snapshots = [];
        $this->manager->perform($this->cluster, 'lxc', 102, 'disk-grow', ['disk' => 'rootfs', 'size_gib' => 16, 'expected_digest' => 'fixture-digest', 'expected_bytes' => 8589934592, 'confirm' => '1'], bin2hex(random_bytes(16)), $this->admin);
        self::assertSame('/nodes/pve-b/lxc/102/resize', $this->fixture->writes()[0]['path']);
        self::assertSame('16G', $this->fixture->writes()[0]['body']['size']);
    }

    public function testDiskExpansionRejectsUnsafeOrStaleRequestsWithoutWrites(): void
    {
        $base = ['disk' => 'scsi0', 'size_gib' => 40, 'expected_digest' => 'fixture-digest', 'expected_bytes' => 34359738368, 'confirm' => '1'];
        foreach ([['expected_digest' => 'outdated-config'], ['confirm' => ''], ['size_gib' => 32], ['size_gib' => 31], ['size_gib' => '+8G'], ['size_gib' => '40.5'], ['size_gib' => 65537], ['expected_digest' => 'fixture-digest', 'expected_bytes' => 1], ['disk' => 'unused0'], ['disk' => '../../disk'], ['disk' => 'ide2']] as $change) {
            $this->fixture->configs[101] = ['scsi0' => 'local:disk,size=32G', 'ide2' => 'local:iso/dvd.iso,media=cdrom,size=1G'];
            $this->fixture->snapshots = [];
            try {
                $this->manager->perform($this->cluster, 'qemu', 101, 'disk-grow', array_replace($base, $change), bin2hex(random_bytes(16)), $this->admin);
                self::fail('Unsafe expansion accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $this->fixture->writes());
            }
        }
        foreach (['running', 'snapshot', 'locked', 'raw', 'readonly', 'shared'] as $case) {
            $this->fixture->guests[0]['status'] = 'running' === $case ? 'running' : 'stopped';
            $this->fixture->snapshots = 'snapshot' === $case ? [['name' => 'before-change']] : [];
            $this->fixture->configs[101] = ['scsi0' => ('raw' === $case ? '/dev/sda' : 'local:disk').',size=32G'.('readonly' === $case ? ',ro=1' : '').('shared' === $case ? ',shared=1' : '')];
            if ('locked' === $case) { $this->fixture->configs[101]['lock'] = 'backup'; }
            try {
                $this->manager->perform($this->cluster, 'qemu', 101, 'disk-grow', $base, bin2hex(random_bytes(16)), $this->admin);
                self::fail('Unsafe disk state accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $this->fixture->writes());
            }
        }
    }

    public function testCustomerCannotExpandDisksEvenWhenAssigned(): void
    {
        $this->assign();
        $this->expectExceptionMessage('pve.error.forbidden');
        try {
            $this->manager->perform($this->cluster, 'qemu', 101, 'disk-grow', [], bin2hex(random_bytes(16)), $this->customer);
        } finally { self::assertSame([], $this->fixture->writes()); }
    }

    public function testTokensAreEncryptedAndNeverSerializedInConnection(): void
    {
        $stored = $this->em->getConnection()->fetchOne('SELECT encrypted_token FROM proxmox_clusters');
        self::assertStringNotContainsString('synthetic-secret', $stored);
        $connection = $this->cluster->connection(self::getContainer()->get(SecretsCrypto::class));
        self::assertSame('synthetic-secret', $connection->tokenSecret);
        self::assertStringNotContainsString('synthetic-secret', json_encode($connection));
        $this->cluster->configure('Renamed', 'https://pve.example.test:8006', 'panel@pve!test', '', self::getContainer()->get(SecretsCrypto::class));
        self::assertSame('synthetic-secret', $this->cluster->connection(self::getContainer()->get(SecretsCrypto::class))->tokenSecret);
        $this->expectException(\InvalidArgumentException::class);
        $this->cluster->configure('Redirect', 'https://another.example.test', 'panel@pve!test', '', self::getContainer()->get(SecretsCrypto::class));
    }

    public function testOverviewIncludesBothClusterNodesAndGuestKinds(): void
    {
        $overview = $this->manager->overview($this->cluster);
        self::assertCount(2, $overview['nodes']);
        self::assertSame(['qemu', 'lxc', 'qemu'], array_column($overview['guests'], 'type'));
    }

    public function testRepeatedSubmissionDoesNotRepeatPowerAction(): void
    {
        $key = bin2hex(random_bytes(16));
        $task = $this->manager->perform($this->cluster, 'qemu', 101, 'start', [], $key, $this->admin);
        self::assertSame($task, $this->manager->perform($this->cluster, 'qemu', 101, 'start', [], $key, $this->admin));
        self::assertCount(1, $this->fixture->writes());
        self::assertSame('running', $task->getState());
        $this->fixture->complete = true;
        $this->manager->refreshTask($task);
        self::assertSame('succeeded', $task->getState());
        self::assertNotNull($task->getFinishedAt());
    }

    public function testMigratedGuestUsesCurrentNode(): void
    {
        $this->fixture->guests[0]['node'] = 'pve-b';
        $task = $this->manager->perform($this->cluster, 'qemu', 101, 'start', [], bin2hex(random_bytes(16)), $this->admin);
        self::assertSame('pve-b', $task->getNode());
        self::assertSame('/nodes/pve-b/qemu/101/status/start', $this->fixture->writes()[0]['path']);
    }

    public function testSnapshotCreateAndCompletionAreTracked(): void
    {
        $task = $this->manager->perform($this->cluster, 'qemu', 101, 'snapshot-create', ['snapshot' => 'release-ready'], bin2hex(random_bytes(16)), $this->admin);
        self::assertContains('release-ready', array_column($this->fixture->snapshots, 'name'));
        self::assertSame('UPID:pve-a:snapshot', $task->getUpid());
        $this->fixture->complete = true;
        $this->manager->refreshTask($task);
        self::assertSame('OK', $task->getResult());
    }

    public static function invalidOperations(): array
    {
        return [
            ['stop', []], ['snapshot-create', ['snapshot' => 'current']], ['snapshot-create', ['snapshot' => '../escape']],
            ['snapshot-delete', ['snapshot' => 'not-present', 'confirm' => '1']],
            ['backup', ['storage' => 'disabled']], ['backup', ['storage' => 'local-lvm']],
            ['backup-delete', ['storage' => 'local', 'volume' => 'local:backup/vzdump-qemu-999-2026_11_12.vma.zst', 'confirm' => '1']],
            ['iso-mount', ['iso' => 'local:iso/absent.iso', 'confirm' => '1']],
            ['clone', ['name' => 'must-not-clone-a-non-template']],
        ];
    }

    #[DataProvider('invalidOperations')]
    public function testInvalidOperationsNeverMutateProxmox(string $action, array $input): void
    {
        try {
            $this->manager->perform($this->cluster, 'qemu', 101, $action, $input, bin2hex(random_bytes(16)), $this->admin);
            self::fail('Invalid action accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->fixture->writes());
        }
    }

    public function testGuestDetailNeverIncludesOtherGuestsBackups(): void
    {
        $detail = $this->manager->detail($this->cluster, 'qemu', 101);
        self::assertCount(1, $detail['backups']);
        self::assertSame(101, $detail['backups'][0]['vmid']);
    }

    public function testRestoreReplacesOnlyStoppedSelectedGuestAfterConfirmation(): void
    {
        $this->manager->perform($this->cluster, 'qemu', 101, 'backup-restore', ['storage' => 'local', 'target_storage' => 'local-lvm', 'volume' => 'local:backup/vzdump-qemu-101-2026_11_12.vma.zst', 'confirm' => '1'], bin2hex(random_bytes(16)), $this->admin);
        $write = $this->fixture->writes()[0];
        self::assertSame('/nodes/pve-a/qemu', $write['path']);
        self::assertSame('101', $write['body']['vmid']);
        self::assertSame('1', $write['body']['force']);
        self::assertSame('0', $write['body']['start']);
    }

    public function testCustomerCannotUseAnUnassignedOrReplacedVm(): void
    {
        $this->assign();
        $this->fixture->guests[0]['tags'] = 'replacement-vm';
        try {
            $this->manager->perform($this->cluster, 'qemu', 101, 'start', [], bin2hex(random_bytes(16)), $this->customer);
            self::fail('VM ID reuse granted access.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('pve.error.identity', $error->getMessage());
        }
        self::assertSame([], $this->fixture->writes());
    }

    public function testCustomerBackupIsRestrictedToAssignedStorage(): void
    {
        $this->assign();
        $task = $this->manager->perform($this->cluster, 'qemu', 101, 'backup', ['storage' => 'local'], bin2hex(random_bytes(16)), $this->customer);
        self::assertSame('running', $task->getState());
        self::assertSame('101', $this->fixture->writes()[0]['body']['vmid']);
        self::assertSame('0', $this->fixture->writes()[0]['body']['remove']);
    }

    public function testCustomerCannotRestoreOrDeleteBackups(): void
    {
        $this->assign();
        foreach (['backup-restore', 'backup-delete', 'create-vm', 'resources-update', 'boot-order', 'guest-agent'] as $action) {
            try {
                $this->manager->perform($this->cluster, 'qemu', 101, $action, ['confirm' => '1'], bin2hex(random_bytes(16)), $this->customer);
                self::fail('Forbidden customer action accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('pve.error.forbidden', $error->getMessage());
            }
        }
        self::assertSame([], $this->fixture->writes());
    }

    #[DataProvider('resourceKinds')]
    public function testResourceChangesRetainTopologyAndUseCurrentConfigDigest(string $kind, int $vmId): void
    {
        foreach ($this->fixture->guests as &$guest) {
            if ($guest['vmid'] === $vmId) {
                $guest['status'] = 'stopped';
            }
        } unset($guest);
        $this->fixture->configs[$vmId] = ['sockets' => 2, 'cores' => 2, 'memory' => 2048, 'net0' => 'private-network'];
        $key = bin2hex(random_bytes(16));
        $input = ['cores' => '4', 'memory' => '4096', 'sockets' => '99', 'digest' => 'client-forged-digest', 'net0' => 'untrusted'];
        $task = $this->manager->perform($this->cluster, $kind, $vmId, 'resources-update', $input, $key, $this->admin);
        self::assertSame('succeeded', $task->getState());
        $writes = $this->fixture->writes();
        self::assertCount(1, $writes);
        self::assertSame('PUT', $writes[0]['method']);
        self::assertStringEndsWith('/'.$kind.'/'.$vmId.'/config', $writes[0]['path']);
        self::assertSame(['cores' => '4', 'memory' => '4096', 'digest' => 'fixture-digest'], $writes[0]['body']);
        self::assertSame(2, $this->fixture->configs[$vmId]['sockets']);
        self::assertSame('private-network', $this->fixture->configs[$vmId]['net0']);
        self::assertSame($task->getId(), $this->manager->perform($this->cluster, $kind, $vmId, 'resources-update', $input, $key, $this->admin)->getId());
        self::assertCount(1, $this->fixture->writes());
    }

    public static function resourceKinds(): iterable
    {
        yield 'QEMU' => ['qemu', 101];
        yield 'LXC' => ['lxc', 102];
    }

    public function testRunningGuestResourceChangeIsRejectedBeforeAnyWrite(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->manager->perform($this->cluster, 'lxc', 102, 'resources-update', ['cores' => '2', 'memory' => '2048'], bin2hex(random_bytes(16)), $this->admin);
        } finally {
            self::assertSame([], $this->fixture->writes());
        }
    }

    public function testInvalidResourceLimitsCannotReachTheApi(): void
    {
        foreach ([['cores' => '0', 'memory' => '2048'], ['cores' => '2', 'memory' => '0'], ['cores' => '9999', 'memory' => '2048']] as $input) {
            try {
                $this->manager->perform($this->cluster, 'qemu', 101, 'resources-update', $input, bin2hex(random_bytes(16)), $this->admin);
                self::fail('Invalid resources accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $this->fixture->writes());
            }
        }
    }

    public function testIsoCreationUsesOnlyValidatedChoices(): void
    {
        $this->manager->perform($this->cluster, 'qemu', 0, 'create-vm', ['node' => 'pve-a', 'name' => 'test-vm', 'iso' => 'local:iso/debian.iso', 'storage' => 'local-lvm', 'cores' => '2', 'memory' => '2048', 'disk' => '32', 'bridge' => 'vmbr0'], bin2hex(random_bytes(16)), $this->admin);
        $body = $this->fixture->writes()[0]['body'];
        self::assertSame('1001', $body['vmid']);
        self::assertSame('local-lvm:32', $body['scsi0']);
        self::assertSame('virtio,bridge=vmbr0', $body['net0']);
        self::assertSame('0', $body['start']);
    }

    public function testApiFailureIsRecordedWithoutLeakingProviderMessage(): void
    {
        // The read phase works, but dispatch fails after the operation was claimed.
        $this->fixture->fail = false;
        $task = $this->manager->perform($this->cluster, 'qemu', 101, 'start', [], bin2hex(random_bytes(16)), $this->admin);
        $this->fixture->fail = true;
        $this->expectException(\App\Module\Nodes\Domain\Proxmox\ProxmoxApiException::class);
        $this->manager->refreshTask($task);
    }

    public function testSingleHostWorksWithoutAdditionalClusterNodes(): void
    {
        $this->fixture->nodes = [$this->fixture->nodes[0]];
        $this->fixture->guests = [$this->fixture->guests[0]];
        self::assertCount(1, $this->manager->overview($this->cluster)['nodes']);
        $task = $this->manager->perform($this->cluster, 'qemu', 101, 'start', [], bin2hex(random_bytes(16)), $this->admin);
        self::assertSame('running', $task->getState());
    }

    public function testServerWriteErrorRetainsClaimWithNoProviderMessageOrSecret(): void
    {
        $this->fixture->failWrites = true;
        $task = $this->manager->perform($this->cluster, 'qemu', 101, 'start', [], bin2hex(random_bytes(16)), $this->admin);
        self::assertSame('uncertain', $task->getState());
        self::assertSame('dispatch_unknown', $task->getResult());
        self::assertNull($task->getFinishedAt());
        self::assertStringNotContainsString('private-upstream-error', json_encode($this->em->getConnection()->fetchAllAssociative('SELECT * FROM proxmox_tasks')));
    }

    public function testLostWriteResponseRequiresReviewAndCannotBeRetriedWithANewKey(): void
    {
        $this->fixture->loseWriteResponse = true;
        $task = $this->manager->perform($this->cluster, 'qemu', 101, 'start', [], bin2hex(random_bytes(16)), $this->admin);
        self::assertSame('uncertain', $task->getState());
        self::assertTrue($task->isActive());
        $this->manager->refreshTask($task);
        self::assertSame('uncertain', $task->getState());
        $writes = array_filter($this->fixture->requests, static fn (array $request) => 'GET' !== $request['method']);
        self::assertCount(1, $writes);
        $this->fixture->loseWriteResponse = false;
        try {
            $this->manager->perform($this->cluster, 'qemu', 101, 'start', [], bin2hex(random_bytes(16)), $this->admin);
            self::fail('An uncertain request must retain its VM lock.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('pve.error.task_active', $exception->getMessage());
        }
        self::assertCount(1, array_filter($this->fixture->requests, static fn (array $request) => 'GET' !== $request['method']));
        $this->manager->reviewTask($task, $this->admin, false, 'Proxmox-Auftragsliste und VM-Zustand manuell geprüft.');
        self::assertSame('failed', $task->getState());
        self::assertSame('reviewed_failure', $task->getResult());
        self::assertFalse($task->isActive());
        self::assertSame('running', $this->manager->perform($this->cluster, 'qemu', 101, 'start', [], bin2hex(random_bytes(16)), $this->admin)->getState());
    }

    public function testKnownClientRejectionReleasesTheClaim(): void
    {
        $this->fixture->failWrites = true;
        $this->fixture->writeFailureStatus = 400;
        $task = $this->manager->perform($this->cluster, 'qemu', 101, 'start', [], bin2hex(random_bytes(16)), $this->admin);
        self::assertSame('failed', $task->getState());
        self::assertSame('dispatch_failed', $task->getResult());
        self::assertNotNull($task->getFinishedAt());
    }

    public function testAddsNewBackupIncludedQemuAndLxcVolumesWithoutOverwritingExistingDisks(): void
    {
        $this->fixture->snapshots = [['name' => 'current']];
        $this->fixture->guests[1]['status'] = 'stopped';
        $this->fixture->configs[101] = ['scsi0' => 'local-lvm:original,size=32G'];
        $this->fixture->configs[102] = ['rootfs' => 'local-lvm:root,size=8G'];
        foreach (['qemu' => [101, 'scsi1'], 'lxc' => [102, 'mp0']] as $kind => [$id, $device]) {
            $key = bin2hex(random_bytes(16));
            $input = ['confirm' => '1', 'expected_digest' => 'fixture-digest', 'storage' => 'local-lvm', 'device' => $device, 'size_gib' => '8', 'mountpoint' => '/mnt/data'];
            $task = $this->manager->perform($this->cluster, $kind, $id, 'disk-add', $input, $key, $this->admin);
            self::assertSame('qemu' === $kind ? 'running' : 'succeeded', $task->getState());
            self::assertSame($task->getId(), $this->manager->perform($this->cluster, $kind, $id, 'disk-add', $input, $key, $this->admin)->getId());
            self::assertStringContainsString('size=8G,backup=1', $this->fixture->configs[$id][$device]);
        }
        self::assertSame('local-lvm:original,size=32G', $this->fixture->configs[101]['scsi0']);
        self::assertSame('local-lvm:root,size=8G', $this->fixture->configs[102]['rootfs']);
        self::assertCount(2, $this->fixture->writes());
        self::assertSame('POST', $this->fixture->writes()[0]['method']);
        self::assertSame('PUT', $this->fixture->writes()[1]['method']);
    }

    public function testAddingDisksFailsBeforeWritingForRunningGuestsSnapshotsAndWrongStorage(): void
    {
        $input = ['confirm' => '1', 'expected_digest' => 'fixture-digest', 'storage' => 'local-lvm', 'device' => 'scsi1', 'size_gib' => '8'];
        foreach (['running', 'snapshot', 'storage', 'customer'] as $case) {
            $this->fixture->guests[0]['status'] = 'running' === $case ? 'running' : 'stopped';
            $this->fixture->snapshots = 'snapshot' === $case ? [['name' => 'before-update']] : [['name' => 'current']];
            try {
                $this->manager->perform($this->cluster, 'qemu', 101, 'disk-add', array_replace($input, ['storage' => 'storage' === $case ? 'disabled' : 'local-lvm']), bin2hex(random_bytes(16)), 'customer' === $case ? $this->customer : $this->admin);
                self::fail('Unsafe disk addition was accepted: '.$case);
            } catch (\InvalidArgumentException) {
                self::assertSame([], $this->fixture->writes());
            }
        }
    }

    public function testCustomerDetailsRequireFreshOwnershipConfiguration(): void
    {
        $this->fixture->configReadError = 403;
        try {
            $this->manager->detail($this->cluster, 'qemu', 101, 'local', true, 'easywi-owned-0123456789abcdef');
            self::fail('Customer details must not fall back to an unverified inventory tag.');
        } catch (\App\Module\Nodes\Domain\Proxmox\ProxmoxApiException $exception) {
            self::assertSame(403, $exception->statusCode);
            self::assertSame([], array_filter($this->fixture->requests, static fn (array $request) => str_ends_with($request['path'], '/status/current')));
        }
    }

    public function testInterruptedSubmissionIsNotUnlockedByTheFormerTwoMinuteTimeout(): void
    {
        $task = new \App\Module\Nodes\Domain\Entity\ProxmoxTask($this->cluster, 'pve-a', 'qemu', 101, 'start', bin2hex(random_bytes(16)), $this->admin->getId());
        (new \ReflectionProperty($task, 'createdAt'))->setValue($task, new \DateTimeImmutable('-3 minutes'));
        $this->em->persist($task);
        $this->em->flush();
        $this->manager->refreshTask($task);
        self::assertSame('uncertain', $task->getState());
        self::assertTrue($task->isActive());
        self::assertSame('qemu:101', $this->em->getConnection()->fetchOne('SELECT guest_lock FROM proxmox_tasks WHERE id = ?', [$task->getId()]));
        self::assertSame([], $this->fixture->requests);
    }

    public function testReviewRequiresAuditPermissionsAndNoRunningUpstreamTasks(): void
    {
        $this->fixture->loseWriteResponse = true;
        $task = $this->manager->perform($this->cluster, 'qemu', 101, 'start', [], bin2hex(random_bytes(16)), $this->admin);
        $this->fixture->loseWriteResponse = false;
        foreach (['permissions', 'active', 'offline', 'customer'] as $case) {
            $this->fixture->auditPermission = 'permissions' !== $case;
            $this->fixture->activeJobs = 'active' === $case ? [['upid' => 'UPID:pve-a:still-running']] : [];
            $this->fixture->nodes[1]['status'] = 'offline' === $case ? 'offline' : 'online';
            try {
                $this->manager->reviewTask($task, 'customer' === $case ? $this->customer : $this->admin, true, 'Prüfnotiz zum unbestätigten Proxmox-Auftrag.');
                self::fail('Review must fail closed: '.$case);
            } catch (\InvalidArgumentException) {
                self::assertSame('uncertain', $task->getState());
                self::assertTrue($task->isActive());
            }
        }
    }

    public function testCustomerCannotAccessUnassignedVmOrChooseAnotherBackupStorage(): void
    {
        $this->assign();
        foreach ([[102, 'lxc', 'start', []], [101, 'qemu', 'backup', ['storage' => 'disabled']]] as [$id, $kind, $action, $input]) {
            try {
                $this->manager->perform($this->cluster, $kind, $id, $action, $input, bin2hex(random_bytes(16)), $this->customer);
                self::fail('Forbidden guest/storage accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $this->fixture->writes());
            }
        }
    }

    public function testActiveTaskBlocksAnotherActionAndCompletionReleasesGuest(): void
    {
        $first = $this->manager->perform($this->cluster, 'qemu', 101, 'start', [], bin2hex(random_bytes(16)), $this->admin);
        try {
            $this->manager->perform($this->cluster, 'qemu', 101, 'stop', ['confirm' => '1'], bin2hex(random_bytes(16)), $this->admin);
            self::fail('Overlapping action accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('pve.error.task_active', $error->getMessage());
        }
        $this->fixture->complete = true;
        $next = $this->manager->perform($this->cluster, 'qemu', 101, 'stop', ['confirm' => '1'], bin2hex(random_bytes(16)), $this->admin);
        self::assertSame('succeeded', $first->getState());
        self::assertSame('running', $next->getState());
        self::assertCount(2, $this->fixture->writes());
    }

    public function testLxcTemplatesCloneWithTheCorrectHostnameField(): void
    {
        $this->fixture->guests[] = ['vmid' => 901, 'type' => 'lxc', 'node' => 'pve-b', 'name' => 'lxc-template', 'template' => 1];
        $this->manager->perform($this->cluster, 'lxc', 901, 'clone', ['name' => 'test-container'], bin2hex(random_bytes(16)), $this->admin);
        $write = $this->fixture->writes()[0];
        self::assertSame('/nodes/pve-b/lxc/901/clone', $write['path']);
        self::assertSame('test-container', $write['body']['hostname']);
        self::assertArrayNotHasKey('name', $write['body']);
    }

    public function testIsoMountPreservesCloudInitAndExistingHardDisks(): void
    {
        $this->fixture->configs[101] = ['ide2' => 'local-lvm:vm-101-cloudinit,media=cdrom', 'ide0' => 'local-lvm:vm-101-disk-0,size=32G'];
        $this->manager->perform($this->cluster, 'qemu', 101, 'iso-mount', ['iso' => 'local:iso/debian.iso', 'confirm' => '1'], bin2hex(random_bytes(16)), $this->admin);
        $write = $this->fixture->writes()[0]['body'];
        self::assertSame('local:iso/debian.iso,media=cdrom', $write['ide1']);
        self::assertArrayNotHasKey('ide0', $write);
        self::assertArrayNotHasKey('ide2', $write);
        self::assertSame('fixture-digest', $write['digest']);
    }

    public function testCloudInitOnlyWritesValidatedDefaultsAndServerDigest(): void
    {
        $this->assign();
        $this->fixture->configs[101] = ['ide2' => 'local-lvm:vm-101-cloudinit,media=cdrom', 'scsi0' => 'private-root-disk'];
        $key = \phpseclib3\Crypt\EC::createKey('Ed25519')->getPublicKey()->toString('OpenSSH');
        $task = $this->manager->perform($this->cluster, 'qemu', 101, 'cloud-init', ['hostname' => 'web-test', 'username' => 'debian', 'ssh_keys' => $key, 'digest' => 'forged', 'scsi0' => 'untrusted', 'cicustom' => 'untrusted'], bin2hex(random_bytes(16)), $this->customer);
        self::assertSame('succeeded', $task->getState());
        self::assertSame(['name' => 'web-test', 'ciuser' => 'debian', 'sshkeys' => rawurlencode($key), 'digest' => 'fixture-digest'], $this->fixture->writes()[0]['body']);
        self::assertSame('private-root-disk', $this->fixture->configs[101]['scsi0']);
        $detail = $this->manager->detail($this->cluster, 'qemu', 101, 'local', true, 'easywi-owned-0123456789abcdef');
        self::assertNull($detail['configuration']);
        self::assertSame($key, $detail['provisioning']['ssh_keys']);
    }

    public function testCloudInitRechecksIdentityOnFreshConfiguration(): void
    {
        $this->assign();
        $this->fixture->configs[101] = ['ide2' => 'local-lvm:vm-101-cloudinit,media=cdrom'];
        $this->fixture->configTags[101] = 'replaced-after-cluster-resource-lookup';
        $this->expectExceptionMessage('pve.error.identity');
        try {
            $this->manager->perform($this->cluster, 'qemu', 101, 'cloud-init', [], bin2hex(random_bytes(16)), $this->customer);
        } finally {
            self::assertSame([], $this->fixture->writes());
        }
    }

    public function testCloudInitRejectsMissingDriveCustomDataAndRunningGuest(): void
    {
        foreach ([[], ['ide2' => 'local:iso/cloudinit.iso,media=cdrom'], ['ide2' => 'local-lvm:vm-101-cloudinit,media=cdrom', 'cicustom' => 'user=custom']] as $config) {
            $this->fixture->configs[101] = $config;
            try {
                $this->manager->perform($this->cluster, 'qemu', 101, 'cloud-init', [], bin2hex(random_bytes(16)), $this->admin);
                self::fail('Unsupported Cloud-Init accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('vm_config.no_cloud_init', $error->getMessage());
            }
        }
        $this->fixture->guests[0]['status'] = 'running';
        try {
            $this->manager->perform($this->cluster, 'qemu', 101, 'cloud-init', [], bin2hex(random_bytes(16)), $this->admin);
            self::fail('Live update accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('pve.error.resources_running', $error->getMessage());
        }
        self::assertSame([], $this->fixture->writes());
    }

    public function testBootOrderAndAgentConfigurationPreserveOtherOptions(): void
    {
        $this->fixture->configs[101] = ['scsi0' => 'disk', 'net0' => 'network', 'ide2' => 'local-lvm:vm-101-cloudinit,media=cdrom', 'agent' => 'enabled=0,freeze-fs-on-backup=0,type=virtio'];
        foreach (['ide2', 'scsi9', 'scsi0;scsi0', 'scsi0;net0,extra=1', ''] as $order) {
            try {
                $this->manager->perform($this->cluster, 'qemu', 101, 'boot-order', ['boot_order' => $order], bin2hex(random_bytes(16)), $this->admin);
                self::fail('Invalid boot order accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('vm_config.invalid_boot', $error->getMessage());
            }
        }
        self::assertSame([], $this->fixture->writes());
        $this->manager->perform($this->cluster, 'qemu', 101, 'boot-order', ['boot_order' => 'net0;scsi0'], bin2hex(random_bytes(16)), $this->admin);
        self::assertSame(['boot' => 'order=net0;scsi0', 'digest' => 'fixture-digest'], $this->fixture->writes()[0]['body']);
        $this->manager->perform($this->cluster, 'qemu', 101, 'guest-agent', [], bin2hex(random_bytes(16)), $this->admin);
        self::assertSame(['agent' => 'enabled=1,freeze-fs-on-backup=0,type=virtio', 'digest' => 'fixture-digest'], $this->fixture->writes()[1]['body']);
    }

    public function testGuestAgentReportsObservedInfoWithReadOnlyCachedCalls(): void
    {
        $this->assign();
        $this->fixture->guests[0]['status'] = 'running';
        $this->fixture->configs[101] = ['agent' => 'enabled=1,freeze-fs-on-backup=0'];
        $report = $this->manager->guestAgentStatus($this->cluster, 'qemu', 101, $this->customer);
        self::assertSame('reachable', $report['state']);
        self::assertSame('actual-web', $report['hostname']);
        self::assertSame('9.2.0', $report['version']);
        self::assertSame('Debian GNU/Linux 13', $report['os']);
        self::assertSame('available', $report['network']['state']);
        self::assertSame('2001:db8::10', $report['network']['interfaces'][0]['addresses'][1]['address']);
        self::assertArrayNotHasKey('private', $report);
        $second = $this->manager->guestAgentStatus($this->cluster, 'qemu', 101, $this->customer);
        self::assertSame($report, $second);
        $reads = array_filter($this->fixture->requests, static fn ($row) => str_contains($row['path'], '/agent/'));
        self::assertCount(4, $reads);
        self::assertSame([], $this->fixture->writes());
        // Cached data must never bypass the fresh identity check.
        $this->fixture->configTags[101] = 'replaced';
        $this->expectExceptionMessage('pve.error.identity');
        $this->manager->guestAgentStatus($this->cluster, 'qemu', 101, $this->customer);
    }

    public function testGuestAgentIsNotContactedForStoppedDisabledOrUnassignedVm(): void
    {
        self::assertSame('not_running', $this->manager->guestAgentStatus($this->cluster, 'qemu', 101, $this->admin)['state']);
        $this->fixture->guests[0]['status'] = 'running';
        self::assertSame('disabled', $this->manager->guestAgentStatus($this->cluster, 'qemu', 101, $this->admin)['state']);
        try {
            $this->manager->guestAgentStatus($this->cluster, 'qemu', 101, $this->customer);
            self::fail('Unassigned customer access.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('pve.error.forbidden', $error->getMessage());
        }
        self::assertSame([], array_values(array_filter($this->fixture->requests, static fn ($row) => str_contains($row['path'], '/agent/'))));
    }

    public function testGuestAgentPermissionFailureHasNoRawProviderError(): void
    {
        $this->fixture->guests[0]['status'] = 'running';
        $this->fixture->configs[101] = ['agent' => '1'];
        $this->fixture->guestAgentError = 403;
        $report = $this->manager->guestAgentStatus($this->cluster, 'qemu', 101, $this->admin);
        self::assertSame('forbidden', $report['state']);
        self::assertStringNotContainsString('private guest', json_encode($report));
        self::assertCount(1, array_filter($this->fixture->requests, static fn ($row) => str_contains($row['path'], '/agent/')));
    }

    public function testFailedNetworkReadPreservesGuestReachabilityWithoutStaleAddresses(): void
    {
        $this->fixture->guests[0]['status'] = 'running';
        $this->fixture->configs[101] = ['agent' => '1'];
        $this->fixture->guestNetworkError = true;
        $report = $this->manager->guestAgentStatus($this->cluster, 'qemu', 101, $this->admin);
        self::assertSame('reachable', $report['state']);
        self::assertSame('unavailable', $report['network']['state']);
        self::assertSame([], $report['network']['interfaces']);
        self::assertSame('actual-web', $report['hostname']);
        self::assertStringNotContainsString('private network', json_encode($report));
        self::assertSame([], $this->fixture->writes());
    }

    public function testCustomerUsageRechecksIdentityBeforeRrdReads(): void
    {
        $this->assign();
        $this->fixture->guests[0]['status'] = 'running';
        $this->fixture->guests[0]['uptime'] = 600;
        $this->fixture->configTags[101] = 'replacement-guest';
        $this->expectExceptionMessage('pve.error.identity');
        try {
            $this->manager->usage($this->cluster, 'qemu', 101, 'easywi-owned-0123456789abcdef');
        } finally {
            self::assertSame([], array_values(array_filter($this->fixture->requests, static fn ($row) => str_contains($row['path'], '/rrddata'))));
        }
    }

    private function assign(): void
    {
        $this->em->persist(new ProxmoxGuestAssignment($this->cluster, 101, 'qemu', $this->customer, 'easywi-owned-0123456789abcdef', 'local'));
        $this->em->flush();
    }
}
