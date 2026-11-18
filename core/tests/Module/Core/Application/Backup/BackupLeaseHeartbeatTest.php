<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Backup;

use App\Message\RunBackupPlanMessage;
use App\MessageHandler\RunBackupPlanMessageHandler;
use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Application\Backup\Adapter\BackupModuleAdapterInterface;
use App\Module\Core\Application\Backup\Adapter\BackupSnapshot;
use App\Module\Core\Application\Backup\Adapter\RestoreReport;
use App\Module\Core\Application\Backup\Adapter\WebBackupAdapter;
use App\Module\Core\Application\Backup\BackupAdapterRegistry;
use App\Module\Core\Application\Backup\BackupAgent;
use App\Module\Core\Application\Backup\BackupOperationHeartbeat;
use App\Module\Core\Application\Backup\BackupPlan;
use App\Module\Core\Application\Backup\BackupLeaseStoreInterface;
use App\Module\Core\Application\Backup\BackupRun;
use App\Module\Core\Application\Backup\BackupPlanJsonCodec;
use App\Module\Core\Application\Backup\BackupStorageTarget;
use App\Module\Core\Application\Backup\BackupTargetValidator;
use App\Module\Core\Application\Backup\BackupTargetWriterRegistry;
use App\Module\Core\Application\Backup\DbalBackupPlanStore;
use App\Module\Core\Application\Backup\RetentionPolicy;
use App\Module\Core\Application\Backup\Target\BackupTargetWriterInterface;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Command\RestoreBackupPlanCommand;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261117200000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class BackupLeaseHeartbeatTest extends TestCase
{
    private Connection $db;
    private DbalBackupPlanStore $store;
    private DbalBackupPlanStore $competitor;
    private BackupOperationHeartbeat $heartbeat;
    private string $workspace;
    private BackupPlan $plan;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/backup-lease-test-'.bin2hex(random_bytes(8));
        mkdir($this->workspace, 0700);
        mkdir($this->workspace.'/source', 0700);
        file_put_contents($this->workspace.'/source/data', 'actual archive bytes');
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        require_once dirname(__DIR__, 5).'/migrations/Version20261117200000.php';
        $schema = new Schema();
        (new Version20261117200000($this->db, new NullLogger()))->up($schema);
        foreach ($schema->toSql($this->db->getDatabasePlatform()) as $sql) { $this->db->executeStatement($sql); }
        $crypto = new SecretsCrypto('lease-fixture-key');
        $codec = new BackupPlanJsonCodec(new BackupTargetValidator());
        $this->store = new DbalBackupPlanStore($this->db, $crypto, $codec);
        $this->competitor = new DbalBackupPlanStore($this->db, $crypto, $codec);
        $this->heartbeat = new BackupOperationHeartbeat();
        $this->plan = new BackupPlan('lease-plan', 'web', $this->workspace.'/source', new BackupStorageTarget('local', 'Test', ['path' => $this->workspace.'/archives']), new RetentionPolicy(1, 1), options: ['lock_target' => true, 'lock_ttl_seconds' => 60]);
        $this->store->save($this->plan);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        (new Filesystem())->remove($this->workspace);
    }

    public function testTransferRenewsBothNearExpiredLeasesAndRestoresOuterHeartbeat(): void
    {
        $outerCalls = 0;
        $this->heartbeat->enter(static function () use (&$outerCalls): void { ++$outerCalls; });
        $writer = $this->writer(function (): void {
            // Advance the lease boundary without wall-clock sleeps; a transfer progress callback renews both scopes.
            $this->db->executeStatement('UPDATE backup_plan_locks SET expires_at = ?', [time() + 1]);
            $this->heartbeat->pulse(true);
            self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM backup_plan_locks WHERE expires_at >= ?', [time() + 58]));
            self::assertFalse($this->competitor->acquireLock('plan:lease-plan', new \DateTimeImmutable('+5 minutes')));
        });
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects(self::once())->method('log')->with(null, 'backup.plan.run', self::anything())->willReturn(null);
        $handler = new RunBackupPlanMessageHandler($this->store, $this->agent($writer), $audit, $this->heartbeat, $this->store);
        $handler(new RunBackupPlanMessage('lease-plan', false, 'completed-once'));
        self::assertTrue($this->store->hasRunForIdempotency('lease-plan', 'completed-once'));
        $handler(new RunBackupPlanMessage('lease-plan', false, 'completed-once'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM backup_plan_runs'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM backup_plan_locks'));
        self::assertSame(0, $outerCalls);
        $this->heartbeat->pulse(true);
        self::assertSame(1, $outerCalls);
    }

    public function testLostOwnerAbortsWithoutSuccessBookkeepingOrReleasingNewOwner(): void
    {
        $outerCalls = 0;
        $this->heartbeat->enter(static function () use (&$outerCalls): void { ++$outerCalls; });
        $writer = $this->writer(function (): void {
            $this->db->update('backup_plan_locks', ['owner_token' => str_repeat('f', 48), 'expires_at' => time() + 300], ['record_key' => hash('sha256', 'plan:lease-plan')]);
            $this->heartbeat->pulse(true);
        });
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects(self::never())->method('log');
        $handler = new RunBackupPlanMessageHandler($this->store, $this->agent($writer), $audit, $this->heartbeat, $this->store);
        try {
            $handler(new RunBackupPlanMessage('lease-plan', false, 'must-not-record'));
            self::fail('A transfer that lost its owning lease must abort.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('ownership', $error->getMessage());
        }
        self::assertFalse($this->store->hasRunForIdempotency('lease-plan', 'must-not-record'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM backup_plan_locks'));
        self::assertSame(str_repeat('f', 48), $this->db->fetchOne('SELECT owner_token FROM backup_plan_locks'));
        self::assertFalse($this->competitor->acquireLock('plan:lease-plan', new \DateTimeImmutable('+5 minutes')));
        $this->heartbeat->pulse(true);
        self::assertSame(1, $outerCalls);
    }

    public function testCommandLostOwnerCannotReturnSuccessfulApplyAndPreservesNewLease(): void
    {
        $outerCalls = 0;
        $this->heartbeat->enter(static function () use (&$outerCalls): void { ++$outerCalls; });
        $adapter = new class($this->db, $this->heartbeat) implements BackupModuleAdapterInterface {
            public function __construct(private Connection $db, private BackupOperationHeartbeat $heartbeat) {}
            public function module(): string { return 'web'; }
            public function snapshot(string $resourceId): BackupSnapshot { throw new \LogicException('Restore only fixture.'); }
            public function restore(string $resourceId, string $archivePath, bool $dryRun = false): RestoreReport {
                $this->db->update('backup_plan_locks', ['owner_token' => str_repeat('a', 48), 'expires_at' => time() + 300], ['record_key' => hash('sha256', 'plan:lease-plan')]);
                $this->heartbeat->pulse(true);
                return new RestoreReport($dryRun, true, 'Must never report this success.');
            }
        };
        $archive = $this->workspace.'/input.tar';
        (new \PharData($archive))->addFromString('data', 'restore fixture');
        $agent = new BackupAgent(new BackupTargetValidator(), new BackupTargetWriterRegistry([]), new BackupAdapterRegistry([$adapter]), heartbeat: $this->heartbeat);
        $tester = new CommandTester(new RestoreBackupPlanCommand($this->store, $agent, $this->heartbeat, $this->store));
        self::assertSame(1, $tester->execute(['plan' => 'lease-plan', 'archive' => $archive, '--apply' => true]));
        self::assertStringNotContainsString('Must never report this success', $tester->getDisplay());
        self::assertSame(str_repeat('a', 48), $this->db->fetchOne('SELECT owner_token FROM backup_plan_locks'));
        $this->heartbeat->pulse(true);
        self::assertSame(1, $outerCalls);
    }

    public function testTargetAcquireExceptionReleasesAlreadyAcquiredPlanAndKeepsOuterCallback(): void
    {
        $store = new FaultingLeaseStore($this->store);
        $store->failTargetAcquire = true;
        $outerCalls = 0;
        $this->heartbeat->enter(static function () use (&$outerCalls): void { ++$outerCalls; });
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects(self::never())->method('log');
        $handler = new RunBackupPlanMessageHandler($store, $this->agent($this->writer(static function (): void {})), $audit, $this->heartbeat, $store);
        try {
            $handler(new RunBackupPlanMessage('lease-plan', false));
            self::fail('Target acquisition failure must abort.');
        } catch (\RuntimeException $error) { self::assertSame('Target acquisition failed.', $error->getMessage()); }
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM backup_plan_locks'));
        self::assertFalse($store->targetReleaseAttempted);
        $this->heartbeat->pulse(true);
        self::assertSame(1, $outerCalls);
    }

    public function testTargetReleaseExceptionStillReleasesPlanAndRestoresOuterCallback(): void
    {
        $store = new FaultingLeaseStore($this->store);
        $store->failTargetRelease = true;
        $outerCalls = 0;
        $this->heartbeat->enter(static function () use (&$outerCalls): void { ++$outerCalls; });
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects(self::once())->method('log')->willReturn(null);
        $handler = new RunBackupPlanMessageHandler($store, $this->agent($this->writer(static function (): void {})), $audit, $this->heartbeat, $store);
        try {
            $handler(new RunBackupPlanMessage('lease-plan', false));
            self::fail('Target release error must be propagated.');
        } catch (\RuntimeException $error) { self::assertSame('Target release failed.', $error->getMessage()); }
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM backup_plan_locks'));
        self::assertTrue($store->targetReleaseAttempted);
        $this->heartbeat->pulse(true);
        self::assertSame(1, $outerCalls);
    }

    public function testBusyTargetCannotBeStolenOrReleasedByAnotherPlanWorker(): void
    {
        $targetScope = 'target:local:'.hash('sha256', json_encode($this->plan->target()->config(), JSON_THROW_ON_ERROR));
        self::assertTrue($this->competitor->acquireLock($targetScope, new \DateTimeImmutable('+5 minutes')));
        $owner = $this->db->fetchOne('SELECT owner_token FROM backup_plan_locks');
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects(self::never())->method('log');
        $handler = new RunBackupPlanMessageHandler($this->store, $this->agent($this->writer(static function (): void {})), $audit, $this->heartbeat, $this->store);
        $handler(new RunBackupPlanMessage('lease-plan', false));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM backup_plan_locks'));
        self::assertSame($owner, $this->db->fetchOne('SELECT owner_token FROM backup_plan_locks'));
        self::assertTrue($this->competitor->acquireLock('plan:lease-plan', new \DateTimeImmutable('+5 minutes')));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM backup_plan_runs'));
    }

    private function writer(\Closure $callback): BackupTargetWriterInterface
    {
        return new class($callback, $this->workspace) implements BackupTargetWriterInterface {
            public function __construct(private \Closure $callback, private string $workspace) {}
            public function supports(BackupStorageTarget $target): bool { return $target->type() === 'local'; }
            public function write(BackupStorageTarget $target, string $archiveName, string $sourceFile): string {
                ($this->callback)();
                $destination = $this->workspace.'/'.$archiveName;
                copy($sourceFile, $destination);
                return $destination;
            }
        };
    }

    private function agent(BackupTargetWriterInterface $writer): BackupAgent
    {
        return new BackupAgent(new BackupTargetValidator(), new BackupTargetWriterRegistry([$writer]), new BackupAdapterRegistry([new WebBackupAdapter()]), heartbeat: $this->heartbeat);
    }
}

final class FaultingLeaseStore implements BackupLeaseStoreInterface
{
    public bool $failTargetAcquire = false;
    public bool $failTargetRelease = false;
    public bool $targetReleaseAttempted = false;
    public function __construct(private DbalBackupPlanStore $delegate) {}
    public function find(string $planId): ?BackupPlan { return $this->delegate->find($planId); }
    public function all(): iterable { return $this->delegate->all(); }
    public function saveRun(BackupRun $run, ?\DateInterval $idempotencyTtl = null): void { $this->delegate->saveRun($run, $idempotencyTtl); }
    public function hasRunForIdempotency(string $planId, string $idempotencyKey): bool { return $this->delegate->hasRunForIdempotency($planId, $idempotencyKey); }
    public function acquireLock(string $scope, \DateTimeImmutable $expiresAt): bool {
        if ($this->failTargetAcquire && str_starts_with($scope, 'target:')) { throw new \RuntimeException('Target acquisition failed.'); }
        return $this->delegate->acquireLock($scope, $expiresAt);
    }
    public function releaseLock(string $scope): void {
        $this->delegate->releaseLock($scope);
        if (str_starts_with($scope, 'target:')) {
            $this->targetReleaseAttempted = true;
            if ($this->failTargetRelease) { throw new \RuntimeException('Target release failed.'); }
        }
    }
    public function cleanupExpiredState(\DateTimeImmutable $now): void { $this->delegate->cleanupExpiredState($now); }
    public function renewLock(string $scope, \DateTimeImmutable $expiresAt): bool { return $this->delegate->renewLock($scope, $expiresAt); }
}
