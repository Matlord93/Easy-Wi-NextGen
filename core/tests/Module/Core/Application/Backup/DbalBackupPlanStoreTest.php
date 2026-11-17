<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Backup;

use App\Module\Core\Application\Backup\Adapter\BackupModuleAdapterInterface;
use App\Module\Core\Application\Backup\BackupAdapterRegistry;
use App\Module\Core\Application\Backup\BackupPlan;
use App\Module\Core\Application\Backup\BackupPlanJsonCodec;
use App\Module\Core\Application\Backup\BackupRun;
use App\Module\Core\Application\Backup\BackupStorageTarget;
use App\Module\Core\Application\Backup\BackupTargetValidator;
use App\Module\Core\Application\Backup\DbalBackupPlanStore;
use App\Module\Core\Application\Backup\RetentionPolicy;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Command\DeleteBackupPlanCommand;
use App\Module\Core\Command\ImportBackupPlanCommand;
use App\Module\Core\Command\ListBackupPlansCommand;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261117200000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

final class DbalBackupPlanStoreTest extends TestCase
{
    private string $path;
    private Connection $first;
    private Connection $second;
    private DbalBackupPlanStore $store;
    private DbalBackupPlanStore $other;
    private BackupPlanJsonCodec $codec;

    #[\Override]
    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'backup-store-');
        self::assertIsString($path);
        $this->path = $path;
        $this->first = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        $this->second = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        require_once dirname(__DIR__, 5).'/migrations/Version20261117200000.php';
        $schema = new Schema();
        (new Version20261117200000($this->first, new NullLogger()))->up($schema);
        foreach ($schema->toSql($this->first->getDatabasePlatform()) as $sql) {
            $this->first->executeStatement($sql);
        }
        $this->codec = new BackupPlanJsonCodec(new BackupTargetValidator());
        $this->store = $this->newStore($this->first);
        $this->other = $this->newStore($this->second);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->first->close();
        $this->second->close();
        unlink($this->path);
    }

    public function testPlansSurviveNewStoreAndConnectionAndAreFullyEncryptedAtRest(): void
    {
        $plan = $this->plan();
        $this->store->save($plan);
        $loaded = $this->other->find('plan-secret-id');
        self::assertNotNull($loaded);
        self::assertSame($this->codec->encode($plan), $this->codec->encode($loaded));
        self::assertSame('plan-secret-id', iterator_to_array($this->other->all())[0]->id());
        $stored = json_encode($this->second->fetchAllAssociative('SELECT * FROM backup_plans'), JSON_THROW_ON_ERROR);
        foreach (['plan-secret-id', '/srv/customer-secret', 'sftp-password-secret', 'archive-key-secret', 'token-secret', 'local'] as $secret) {
            self::assertStringNotContainsString($secret, $stored);
        }
        $replacement = new BackupPlan($plan->id(), 'web', '/srv/updated', $plan->target(), new RetentionPolicy(4, 12));
        $this->other->save($replacement);
        self::assertSame('/srv/updated', $this->store->find($plan->id())?->resourceId());
        self::assertTrue($this->other->delete($plan->id()));
        self::assertFalse($this->other->delete($plan->id()));
        self::assertNull($this->store->find($plan->id()));
    }

    public function testKeyRotationReadsOldDataButWrongSecretFailsClosed(): void
    {
        $this->store->save($this->plan());
        $rotated = new DbalBackupPlanStore($this->second, new SecretsCrypto('new-key', 'test-app-secret'), $this->codec);
        self::assertSame('plan-secret-id', $rotated->find('plan-secret-id')?->id());
        $wrong = new DbalBackupPlanStore($this->second, new SecretsCrypto('wrong-key'), $this->codec);
        $this->expectException(\RuntimeException::class);
        $wrong->find('plan-secret-id');
    }

    public function testSwappedAuthenticatedPlanPayloadIsRejectedByIdentity(): void
    {
        $this->store->save($this->plan());
        $this->first->executeStatement('UPDATE backup_plans SET record_key = ?', [hash('sha256', 'other-plan')]);
        $this->expectException(\RuntimeException::class);
        $this->other->find('other-plan');
    }

    public function testCrossConnectionLeasesAreAtomicAndOnlyTheirOwnerCanRelease(): void
    {
        $future = new \DateTimeImmutable('+5 minutes');
        self::assertTrue($this->store->acquireLock('plan:p1', $future));
        self::assertFalse($this->other->acquireLock('plan:p1', $future));
        $this->other->releaseLock('plan:p1');
        self::assertFalse($this->other->acquireLock('plan:p1', $future));
        $this->store->releaseLock('plan:p1');
        self::assertTrue($this->other->acquireLock('plan:p1', $future));
        self::assertTrue($this->store->acquireLock('plan:p2', $future));
        self::assertFalse($this->store->acquireLock('expired', new \DateTimeImmutable('-1 second')));
    }

    public function testExpiredTakeoverCannotBeReleasedByStaleOwner(): void
    {
        $future = new \DateTimeImmutable('+5 minutes');
        self::assertTrue($this->store->acquireLock('plan:p1', $future));
        $this->first->executeStatement('UPDATE backup_plan_locks SET expires_at = ?', [time() - 1]);
        self::assertTrue($this->other->acquireLock('plan:p1', $future));
        $this->store->releaseLock('plan:p1');
        self::assertFalse($this->store->acquireLock('plan:p1', $future));
        $this->other->releaseLock('plan:p1');
        self::assertTrue($this->store->acquireLock('plan:p1', $future));
    }

    public function testOnlyLiveLeaseOwnerCanRenewAcrossConnections(): void
    {
        $expires = new \DateTimeImmutable('+5 minutes');
        self::assertFalse($this->store->renewLock('plan:renew', $expires));
        self::assertTrue($this->store->acquireLock('plan:renew', $expires));
        self::assertTrue($this->store->renewLock('plan:renew', $expires));
        self::assertFalse($this->other->renewLock('plan:renew', $expires));
        self::assertFalse($this->store->renewLock('plan:renew', new \DateTimeImmutable('-1 second')));
        $this->first->executeStatement('UPDATE backup_plan_locks SET expires_at = ?', [time() - 1]);
        self::assertFalse($this->store->renewLock('plan:renew', $expires));
        self::assertTrue($this->other->acquireLock('plan:renew', $expires));
        self::assertFalse($this->store->renewLock('plan:renew', $expires));
        self::assertTrue($this->other->renewLock('plan:renew', new \DateTimeImmutable('+10 minutes')));
        $this->store->releaseLock('plan:renew');
        self::assertFalse($this->store->acquireLock('plan:renew', $expires));
    }

    public function testIdempotencyPersistsAndExpiresWithoutKeyDelimiterCollisions(): void
    {
        $run = new BackupRun('idem:1', 'plan:1', 'succeeded', '/backup/private-path.tar', 123, 'checksum', new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')), true);
        $this->store->saveRun($run, new \DateInterval('PT1H'));
        self::assertTrue($this->other->hasRunForIdempotency('plan:1', 'idem:1'));
        self::assertFalse($this->other->hasRunForIdempotency('plan', '1:idem:1'));
        $payload = $this->second->fetchOne('SELECT payload FROM backup_plan_runs');
        self::assertIsString($payload);
        self::assertStringNotContainsString('/backup/private-path.tar', $payload);
        $data = json_decode((new SecretsCrypto('test-app-secret'))->decrypt($payload), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(['version' => 1, 'run_id' => 'idem:1', 'plan_id' => 'plan:1', 'status' => 'succeeded', 'archive_path' => '/backup/private-path.tar', 'size_bytes' => 123, 'checksum_sha256' => 'checksum', 'created_at' => $run->createdAt()->format('Y-m-d\TH:i:s.uP'), 'restored' => true], $data);
        $this->other->saveRun(new BackupRun('idem:1', 'plan:1', 'succeeded', '/backup/new.tar', 1, 'sum', new \DateTimeImmutable('-2 hours')), new \DateInterval('PT30M'));
        self::assertFalse($this->store->hasRunForIdempotency('plan:1', 'idem:1'));
        $this->store->cleanupExpiredState(new \DateTimeImmutable());
        self::assertSame(0, $this->second->fetchOne('SELECT COUNT(*) FROM backup_plan_runs'));
        $this->store->saveRun($run);
        self::assertTrue($this->other->hasRunForIdempotency('plan:1', 'idem:1'));
    }

    public function testCleanupPreservesLiveLeasesAndPlans(): void
    {
        $this->store->save($this->plan());
        self::assertTrue($this->store->acquireLock('live', new \DateTimeImmutable('+5 minutes')));
        self::assertTrue($this->other->acquireLock('expired', new \DateTimeImmutable('+1 minute')));
        $this->first->executeStatement('UPDATE backup_plan_locks SET expires_at = ? WHERE record_key = ?', [time() - 1, hash('sha256', 'expired')]);
        $this->other->cleanupExpiredState(new \DateTimeImmutable());
        self::assertFalse($this->other->acquireLock('live', new \DateTimeImmutable('+5 minutes')));
        self::assertTrue($this->store->acquireLock('expired', new \DateTimeImmutable('+5 minutes')));
        self::assertNotNull($this->other->find('plan-secret-id'));
    }

    public function testMigrationProducesPortableMysqlSchemaAndLargeEncryptedPayloadColumn(): void
    {
        $schema = new Schema();
        (new Version20261117200000($this->first, new NullLogger()))->up($schema);
        $sql = implode("\n", $schema->toSql(new MySQLPlatform()));
        self::assertStringContainsString('backup_plans', $sql);
        self::assertStringContainsString('backup_plan_runs', $sql);
        self::assertStringContainsString('backup_plan_locks', $sql);
        self::assertStringContainsString('MEDIUMTEXT', $sql);
    }

    #[DataProvider('invalidPlans')]
    public function testInvalidOperatorPlansNeverPersist(string $field, mixed $value): void
    {
        $data = json_decode($this->codec->encode($this->plan()), true, 64, JSON_THROW_ON_ERROR);
        $data[$field] = $value;
        $this->expectException(\InvalidArgumentException::class);
        $this->codec->decode(json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidPlans(): iterable
    {
        yield 'schema' => ['version', 2];
        yield 'unknown field' => ['unexpected', 'secret'];
        yield 'blank id' => ['id', ''];
        yield 'cron' => ['cron', '* not a cron'];
        yield 'timezone' => ['timezone', 'Unknown/Zone'];
        yield 'retention' => ['retention', ['keep_count' => -1, 'keep_days' => 7]];
        yield 'unsupported target' => ['target', ['type' => 'ftp', 'label' => 'target', 'config' => []]];
        yield 'relative local target' => ['target', ['type' => 'local', 'label' => 'target', 'config' => ['path' => '../backup']]];
        yield 'missing encryption key' => ['options', ['encrypt' => true]];
        yield 'compression typo' => ['options', ['compression' => 'zip']];
        yield 'weak ttl' => ['options', ['lock_ttl_seconds' => 0]];
        yield 'string flag' => ['options', ['encrypt' => 'false']];
        yield 'string retention flag' => ['options', ['retention_enabled' => 'false']];
    }

    public function testAutomaticRetentionRejectsIncompleteSftpTargetBeforeImport(): void
    {
        $data = json_decode($this->codec->encode($this->plan()), true, 64, JSON_THROW_ON_ERROR);
        $data['target'] = ['type' => 'sftp', 'label' => 'Remote', 'config' => ['host' => 'backups.example.test']];
        $data['options']['retention_enabled'] = true;

        $this->expectException(\InvalidArgumentException::class);
        $this->codec->decode(json_encode($data, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('remoteTargets')]
    public function testRemoteRetentionPlanImportsAndSurvivesEncryptedPersistence(BackupStorageTarget $target): void
    {
        $plan = new BackupPlan('remote-plan', 'web', '/srv/site', $target, new RetentionPolicy(7, 30), null, 'UTC', ['retention_enabled' => true]);
        $this->store->save($plan);
        $loaded = $this->other->find('remote-plan');
        self::assertNotNull($loaded);
        self::assertSame($this->codec->encode($plan), $this->codec->encode($loaded));
        self::assertTrue($loaded->options()['retention_enabled']);
    }

    public static function remoteTargets(): iterable
    {
        yield 'webdav' => [new BackupStorageTarget('webdav', 'DAV', ['url' => 'https://example.com', 'remote_path' => 'backups'], ['username' => 'owner', 'password' => 'secret'])];
        yield 'nextcloud config username' => [new BackupStorageTarget('nextcloud', 'NC', ['url' => 'https://example.com', 'username' => 'owner'], ['password' => 'secret'])];
        foreach (['sftp', 'ssh'] as $type) {
            yield $type => [new BackupStorageTarget($type, 'SSH', ['host' => 'storage', 'path' => '/backups', 'host_key_fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', 'host', true)), '=')], ['username' => 'owner', 'private_key_path' => '/etc/easywi/backup-key'])];
        }
        yield 's3' => [new BackupStorageTarget('s3', 'S3', ['bucket' => 'bucket', 'region' => ''], ['access_key' => 'key', 'secret_key' => 'secret'])];
        yield 'smb' => [new BackupStorageTarget('smb', 'SMB', ['host' => 'storage', 'share' => 'backups'], ['username' => 'owner', 'password' => 'secret'])];
        yield 'nfs' => [new BackupStorageTarget('nfs', 'NFS', ['path' => '/mnt/backup-export'])];
    }

    public function testOperatorImportUpdateListDeleteWorkflowDoesNotRevealSecrets(): void
    {
        $adapter = $this->createStub(BackupModuleAdapterInterface::class);
        $adapter->method('module')->willReturn('web');
        $file = $this->path.'.json';
        file_put_contents($file, $this->codec->encode($this->plan()));
        try {
            $import = new CommandTester(new ImportBackupPlanCommand($this->store, $this->codec, new BackupAdapterRegistry([$adapter])));
            self::assertSame(0, $import->execute(['file' => $file]));
            self::assertSame(0, $import->execute(['file' => $file]));
            self::assertCount(1, iterator_to_array($this->other->all()));
            $list = new CommandTester(new ListBackupPlansCommand($this->other));
            self::assertSame(0, $list->execute([]));
            self::assertStringContainsString('plan-secret-id', $list->getDisplay());
            foreach (['sftp-password-secret', 'archive-key-secret', 'token-secret', '/srv/customer-secret'] as $secret) {
                self::assertStringNotContainsString($secret, $import->getDisplay().$list->getDisplay());
            }
            $delete = new CommandTester(new DeleteBackupPlanCommand($this->other));
            self::assertSame(0, $delete->execute(['id' => 'plan-secret-id']));
            self::assertNull($this->store->find('plan-secret-id'));
        } finally {
            unlink($file);
        }
    }

    public function testRejectedImportDoesNotChangeStoredPlanOrLeakRawJson(): void
    {
        $this->store->save($this->plan());
        $file = $this->path.'.json';
        file_put_contents($file, '{"version":1,"options":{"password":"must-not-print"}}');
        try {
            $import = new CommandTester(new ImportBackupPlanCommand($this->store, $this->codec, new BackupAdapterRegistry([])));
            self::assertSame(2, $import->execute(['file' => $file]));
            self::assertStringNotContainsString('must-not-print', $import->getDisplay());
            self::assertNotNull($this->other->find('plan-secret-id'));
        } finally {
            unlink($file);
        }
    }

    private function newStore(Connection $connection): DbalBackupPlanStore
    {
        return new DbalBackupPlanStore($connection, new SecretsCrypto('test-app-secret'), $this->codec);
    }

    private function plan(): BackupPlan
    {
        return new BackupPlan('plan-secret-id', 'web', '/srv/customer-secret', new BackupStorageTarget('local', 'private label', ['path' => '/backups', 'nested' => ['a', ['b' => false]]], ['password' => 'sftp-password-secret', 'token' => 'token-secret']), new RetentionPolicy(4, 14), '0 3 * * *', 'Europe/Berlin', ['compression' => 'gzip', 'encrypt' => true, 'encryption_key' => 'archive-key-secret', 'lock_ttl_seconds' => 3600]);
    }
}
