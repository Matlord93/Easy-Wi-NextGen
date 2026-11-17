<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Backup;

use App\Module\Core\Application\Backup\BackupArchiveInventory;
use App\Module\Core\Application\Backup\BackupArchiveStorageRegistry;
use App\Module\Core\Application\Backup\BackupPlan;
use App\Module\Core\Application\Backup\BackupPlanJsonCodec;
use App\Module\Core\Application\Backup\BackupRetentionRunner;
use App\Module\Core\Application\Backup\BackupRun;
use App\Module\Core\Application\Backup\BackupStorageTarget;
use App\Module\Core\Application\Backup\BackupTargetValidator;
use App\Module\Core\Application\Backup\DbalBackupPlanStore;
use App\Module\Core\Application\Backup\RetentionPolicy;
use App\Module\Core\Application\Backup\RetentionPruner;
use App\Module\Core\Application\Backup\Target\WebDavBackupTargetWriter;
use App\Module\Core\Application\SecretsCrypto;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261117200000;
use DoctrineMigrations\Version20261117210000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RemoteBackupRetentionTest extends TestCase
{
    private Connection $connection;
    private SecretsCrypto $crypto;
    private DbalBackupPlanStore $plans;
    private BackupArchiveInventory $inventory;
    private BackupPlan $plan;
    private BackupRun $run;

    #[\Override]
    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->crypto = new SecretsCrypto('remote-retention-test');
        $this->plans = new DbalBackupPlanStore($this->connection, $this->crypto, new BackupPlanJsonCodec(new BackupTargetValidator()));
        $this->inventory = new BackupArchiveInventory($this->connection, $this->crypto);
        require_once dirname(__DIR__, 5).'/migrations/Version20261117200000.php';
        require_once dirname(__DIR__, 5).'/migrations/Version20261117210000.php';
        $schema = new Schema();
        (new Version20261117200000($this->connection, new NullLogger()))->up($schema);
        (new Version20261117210000($this->connection, new NullLogger()))->up($schema);
        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeStatement($sql);
        }
        $this->plan = $this->plan('remote', new BackupStorageTarget('webdav', 'Remote', ['url' => 'https://example.com', 'remote_path' => 'backups'], ['username' => 'archive-owner', 'password' => 'secret']));
        $this->plans->save($this->plan);
        $this->run = new BackupRun('old', 'remote', 'succeeded', 'https://example.com/backups/old.tar', 7, hash('sha256', 'archive'), new \DateTimeImmutable('-90 days'));
        $this->inventory->record($this->plan, $this->run);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testPreviewVerifiesThenApplyDeletesConditionallyAndForgetsOnlyCompletedDeletion(): void
    {
        $methods = [];
        $writer = new WebDavBackupTargetWriter(new MockHttpClient(function (string $method, string $url, array $options) use (&$methods): MockResponse {
            $methods[] = $method;
            self::assertSame('https://example.com/backups/old.tar', $url);
            if ('DELETE' === $method) {
                self::assertContains('If-Match: "generation-1"', $options['headers']);

                return new MockResponse('', ['http_code' => 204]);
            }

            return new MockResponse('archive', ['http_code' => 200, 'response_headers' => ['ETag: "generation-1"']]);
        }));
        $runner = $this->runner($writer);
        self::assertSame('validated', $runner->prunePlan('remote')->status);
        self::assertSame(['GET'], $methods);
        self::assertCount(1, $this->inventory->forPlan('remote'));
        $report = $runner->prunePlan('remote', true);
        self::assertSame('applied', $report->status, json_encode($report->blocked, JSON_THROW_ON_ERROR));
        self::assertSame([$this->run->archivePath()], $report->deleted);
        self::assertSame(['GET', 'GET', 'DELETE'], $methods);
        self::assertSame([], $this->inventory->forPlan('remote'));
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM backup_plan_locks'));
    }

    #[DataProvider('unverifiableArchives')]
    public function testUnverifiableOrChangedArchivesAreNeverDeleted(string $body, string $etag, int $status): void
    {
        $methods = [];
        $writer = new WebDavBackupTargetWriter(new MockHttpClient(function (string $method) use (&$methods, $body, $etag, $status): MockResponse {
            $methods[] = $method;

            return new MockResponse($body, ['http_code' => $status, 'response_headers' => ['ETag: '.$etag]]);
        }));
        $report = $this->runner($writer)->prunePlan('remote', true);
        self::assertSame('failed', $report->status);
        self::assertSame([], $report->deleted);
        self::assertSame(['GET'], $methods);
        self::assertCount(1, $this->inventory->forPlan('remote'));
    }

    public static function unverifiableArchives(): iterable
    {
        yield 'changed bytes' => ['changed', '"generation-2"', 200];
        yield 'changed size' => ['archive-longer', '"generation-2"', 200];
        yield 'weak ETag' => ['archive', 'W/"generation-1"', 200];
        yield 'missing ETag' => ['archive', '', 200];
        yield 'missing object' => ['', '', 404];
    }

    public function testFailedConditionalDeletionPreservesInventory(): void
    {
        $writer = new WebDavBackupTargetWriter(new MockHttpClient([
            new MockResponse('archive', ['http_code' => 200, 'response_headers' => ['ETag: "generation-1"']]),
            new MockResponse('', ['http_code' => 412]),
        ]));
        $report = $this->runner($writer)->prunePlan('remote', true);
        self::assertSame('failed', $report->status);
        self::assertSame([], $report->deleted);
        self::assertCount(1, $this->inventory->forPlan('remote'));
    }

    public function testHistoryWithoutRemoteTargetIdentityFailsBeforeNetworkAccess(): void
    {
        $this->rewriteInventory(static function (array $data): array {
            unset($data['target_identity']);

            return $data;
        });
        $this->assertBlockedWithoutNetwork();
    }

    public function testRetargetedRootOrAccountFailsBeforeNetworkAccess(): void
    {
        $changed = new BackupStorageTarget('webdav', 'Changed', ['url' => 'https://example.com', 'remote_path' => 'backups'], ['username' => 'new-owner', 'password' => 'secret']);
        $this->plans->save($this->plan('remote', $changed));
        $this->assertBlockedWithoutNetwork();
    }

    public function testSharedArchiveFailsBeforeNetworkAccess(): void
    {
        $other = $this->plan('other', $this->plan->target());
        $this->inventory->record($other, new BackupRun('other', 'other', 'succeeded', $this->run->archivePath(), 7, $this->run->checksumSha256(), $this->run->createdAt()));
        $this->assertBlockedWithoutNetwork();
    }

    public function testLeaseLossDuringDownloadPreventsDeletion(): void
    {
        $methods = [];
        $writer = new WebDavBackupTargetWriter(new MockHttpClient(function (string $method) use (&$methods): MockResponse {
            $methods[] = $method;
            $this->connection->executeStatement('UPDATE backup_plan_locks SET expires_at = ?', [time() - 1]);

            return new MockResponse('archive', ['http_code' => 200, 'response_headers' => ['ETag: "generation-1"']]);
        }));
        $report = $this->runner($writer)->prunePlan('remote', true);
        self::assertSame('failed', $report->status);
        self::assertSame(['GET'], $methods);
        self::assertSame([], $report->deleted);
        self::assertCount(1, $this->inventory->forPlan('remote'));
    }

    public function testPlanChangeDuringDownloadPreventsDeletion(): void
    {
        $methods = [];
        $writer = new WebDavBackupTargetWriter(new MockHttpClient(function (string $method) use (&$methods): MockResponse {
            $methods[] = $method;
            $this->plans->save($this->plan('remote', new BackupStorageTarget('webdav', 'Changed', ['url' => 'https://example.com', 'remote_path' => 'new-root'], ['username' => 'archive-owner', 'password' => 'secret'])));

            return new MockResponse('archive', ['http_code' => 200, 'response_headers' => ['ETag: "generation-1"']]);
        }));
        self::assertSame('failed', $this->runner($writer)->prunePlan('remote', true)->status);
        self::assertSame(['GET'], $methods);
    }

    public function testPostDeletionBookkeepingFailureReportsTheCompletedRemoteDeletion(): void
    {
        $this->connection->executeStatement("CREATE TRIGGER prevent_inventory_delete BEFORE DELETE ON backup_archive_inventory BEGIN SELECT RAISE(ABORT, 'inventory unavailable'); END");
        $writer = new WebDavBackupTargetWriter(new MockHttpClient([
            new MockResponse('archive', ['http_code' => 200, 'response_headers' => ['ETag: "generation-1"']]),
            new MockResponse('', ['http_code' => 204]),
        ]));
        $report = $this->runner($writer)->prunePlan('remote', true);
        self::assertSame('failed', $report->status);
        self::assertSame([$this->run->archivePath()], $report->deleted);
        self::assertCount(1, $this->inventory->forPlan('remote'));
    }

    private function assertBlockedWithoutNetwork(): void
    {
        $requests = 0;
        $writer = new WebDavBackupTargetWriter(new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('archive', ['http_code' => 200, 'response_headers' => ['ETag: "generation-1"']]);
        }));
        self::assertSame('failed', $this->runner($writer)->prunePlan('remote', true)->status);
        self::assertSame(0, $requests);
        self::assertCount(1, $this->inventory->forPlan('remote'));
    }

    private function rewriteInventory(callable $transform): void
    {
        $encrypted = $this->connection->fetchOne('SELECT payload FROM backup_archive_inventory');
        self::assertIsString($encrypted);
        $data = $transform(json_decode($this->crypto->decrypt($encrypted), true, 32, JSON_THROW_ON_ERROR));
        $this->connection->executeStatement('UPDATE backup_archive_inventory SET payload = ?', [$this->crypto->encrypt(json_encode($data, JSON_THROW_ON_ERROR))]);
    }

    private function runner(WebDavBackupTargetWriter $writer): BackupRetentionRunner
    {
        return new BackupRetentionRunner($this->plans, $this->inventory, new RetentionPruner(), new NullLogger(), new BackupArchiveStorageRegistry([$writer]));
    }

    private function plan(string $id, BackupStorageTarget $target): BackupPlan
    {
        return new BackupPlan($id, 'web', '/srv/site', $target, new RetentionPolicy(0, 30), null, 'UTC', ['retention_enabled' => true]);
    }
}
