<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Core\Application\Backup\BackupAgent;
use App\Module\Core\Application\Backup\BackupArchiveInventory;
use App\Module\Core\Application\Backup\BackupLeaseStoreInterface;
use App\Module\Core\Application\Backup\BackupPlan;
use App\Module\Core\Application\Backup\BackupRetentionRunner;
use App\Module\Core\Application\Backup\BackupRun;
use App\Module\Core\Application\Backup\BackupStorageTarget;
use App\Module\Core\Application\Backup\RetentionPolicy;
use App\Module\Core\Application\Backup\WritableBackupPlanStoreInterface;
use App\Module\Core\Application\Scheduler\BackupsCleanupScheduleHandler;
use App\Module\Core\Application\Scheduler\ScheduleHandlerRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DoctrineMigrations\Version20261117200000;
use DoctrineMigrations\Version20261117210000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class BackupRetentionWorkflowTest extends KernelTestCase
{
    private array $previousDatabase;
    private string $workspace;
    private Connection $connection;
    private WritableBackupPlanStoreInterface $store;
    private BackupArchiveInventory $inventory;

    #[\Override]
    protected function setUp(): void
    {
        $this->previousDatabase = [$_SERVER['DATABASE_URL'] ?? null, $_ENV['DATABASE_URL'] ?? null];
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        $this->workspace = sys_get_temp_dir().'/backup-retention-'.bin2hex(random_bytes(8));
        mkdir($this->workspace, 0700);
        self::bootKernel();
        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        require_once dirname(__DIR__, 2).'/migrations/Version20261117200000.php';
        require_once dirname(__DIR__, 2).'/migrations/Version20261117210000.php';
        $schema = new Schema();
        (new Version20261117200000($this->connection, new NullLogger()))->up($schema);
        (new Version20261117210000($this->connection, new NullLogger()))->up($schema);
        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeStatement($sql);
        }
        $this->store = self::getContainer()->get(WritableBackupPlanStoreInterface::class);
        $this->inventory = self::getContainer()->get(BackupArchiveInventory::class);
    }

    #[\Override]
    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        foreach (['SERVER', 'ENV'] as $index => $global) {
            if ($this->previousDatabase[$index] === null) {
                unset($GLOBALS['_'.$global]['DATABASE_URL']);
            } else {
                $GLOBALS['_'.$global]['DATABASE_URL'] = $this->previousDatabase[$index];
            }
        }
        (new Filesystem())->remove($this->workspace);
        parent::tearDown();
    }

    public function testEncryptedInventorySurvivesMarkerExpiryAndCliPreviewsBeforeDeletingOnlyRecordedOldArchive(): void
    {
        $plan = $this->plan();
        $old = $this->backup($plan, 'old', '-60 days');
        $middle = $this->backup($plan, 'middle', '-5 days');
        $newest = $this->backup($plan, 'newest', '-1 day');
        $untracked = dirname($old->archivePath()).'/untracked.tar';
        file_put_contents($untracked, 'untracked archive');
        $this->store->saveRun($old, new \DateInterval('PT1S'));
        $this->store->cleanupExpiredState(new \DateTimeImmutable());
        self::assertFalse($this->store->hasRunForIdempotency($plan->id(), $old->runId()));
        self::assertCount(3, $this->inventory->forPlan($plan->id()));
        $ciphertext = implode('', $this->connection->fetchFirstColumn('SELECT payload FROM backup_archive_inventory'));
        self::assertStringNotContainsString($this->workspace, $ciphertext);
        self::assertStringNotContainsString('retention-private-key', $ciphertext);
        $command = new CommandTester((new Application(self::$kernel))->find('app:backup-plans:prune'));

        self::assertSame(0, $command->execute(['plan' => $plan->id()]));
        self::assertStringContainsString('Validation only', $command->getDisplay());
        self::assertStringContainsString($old->archivePath(), $command->getDisplay());
        self::assertFileExists($old->archivePath());
        self::assertSame(0, $command->execute(['plan' => $plan->id(), '--apply' => true]));
        self::assertStringContainsString('deleted: 1', $command->getDisplay());
        self::assertStringNotContainsString('retention-private-key', $command->getDisplay());
        self::assertFileDoesNotExist($old->archivePath());
        self::assertFileExists($middle->archivePath());
        self::assertFileExists($newest->archivePath());
        self::assertFileExists($untracked);
        self::assertCount(2, $this->inventory->forPlan($plan->id()));
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM backup_plan_locks'));
    }

    public function testCentralSchedulerCleansOnlyExplicitlyEnabledPlans(): void
    {
        $enabled = $this->plan();
        $old = $this->backup($enabled, 'old', '-60 days');
        $kept = $this->backup($enabled, 'new', '-1 day');
        $disabled = $this->plan('disabled', false);
        $disabledArchive = $this->backup($disabled, 'disabled-old', '-90 days');
        $handler = self::getContainer()->get(ScheduleHandlerRegistry::class)->get('cleanup.backups');
        self::assertInstanceOf(BackupsCleanupScheduleHandler::class, $handler);
        self::assertSame('failed', $handler->runNow('customer', 'cleanup.backups')->status);

        $result = $handler->runNow('system', 'cleanup.backups');

        self::assertSame('success', $result->status, $result->message);
        self::assertFileDoesNotExist($old->archivePath());
        self::assertFileExists($kept->archivePath());
        self::assertFileExists($disabledArchive->archivePath());
        self::assertStringContainsString('Deleted 1 archives', $result->message);
    }

    public function testChangedArchiveIsNeverDeletedAndInventoryIsRetained(): void
    {
        $plan = $this->plan();
        $old = $this->backup($plan, 'old', '-60 days');
        file_put_contents($old->archivePath(), 'replacement contents');
        $runner = self::getContainer()->get(BackupRetentionRunner::class);

        $preview = $runner->prunePlan($plan->id());
        $result = $runner->prunePlan($plan->id(), true);

        self::assertSame('failed', $preview->status);
        self::assertSame('failed', $result->status);
        self::assertSame([], $result->deleted);
        self::assertStringContainsString('contents changed', $result->blocked[0]['reason']);
        self::assertSame('replacement contents', file_get_contents($old->archivePath()));
        self::assertCount(1, $this->inventory->forPlan($plan->id()));
    }

    public function testSymlinkReplacementCannotDeleteAnOutsideFile(): void
    {
        $plan = $this->plan();
        $old = $this->backup($plan, 'old', '-60 days');
        $outside = $this->workspace.'/outside.tar';
        rename($old->archivePath(), $outside);
        symlink($outside, $old->archivePath());

        $report = self::getContainer()->get(BackupRetentionRunner::class)->prunePlan($plan->id(), true);

        self::assertSame('failed', $report->status);
        self::assertFileExists($outside);
        self::assertTrue(is_link($old->archivePath()));
        self::assertCount(1, $this->inventory->forPlan($plan->id()));
    }

    public function testRetargetedPlanCannotDeleteItsPreviousTargetsFiles(): void
    {
        $plan = $this->plan();
        $old = $this->backup($plan, 'old', '-60 days');
        mkdir($this->workspace.'/replacement', 0700);
        $replacement = new BackupPlan($plan->id(), $plan->module(), $plan->resourceId(), new BackupStorageTarget('local', 'new target', ['path' => $this->workspace.'/replacement']), $plan->retentionPolicy(), options: $plan->options());
        $this->store->save($replacement);

        $report = self::getContainer()->get(BackupRetentionRunner::class)->prunePlan($plan->id(), true);

        self::assertSame('failed', $report->status);
        self::assertFileExists($old->archivePath());
        self::assertCount(1, $this->inventory->forPlan($plan->id()));
    }

    public function testArchiveReferencedByAnotherRunCannotBeDeleted(): void
    {
        $plan = $this->plan();
        $old = $this->backup($plan, 'old', '-60 days');
        $other = $this->plan('other-plan');
        $alias = new BackupRun('alias', $other->id(), 'succeeded', $old->archivePath(), $old->sizeBytes(), $old->checksumSha256(), $old->createdAt());
        $this->inventory->record($other, $alias);

        $report = self::getContainer()->get(BackupRetentionRunner::class)->prunePlan($plan->id(), true);

        self::assertSame('failed', $report->status);
        self::assertStringContainsString('another recorded run', $report->blocked[0]['reason']);
        self::assertFileExists($old->archivePath());
        self::assertCount(1, $this->inventory->forPlan($plan->id()));
    }

    public function testBusyPlanIsLeftUntouchedAndItsOwnerKeepsTheLease(): void
    {
        $plan = $this->plan();
        $old = $this->backup($plan, 'old', '-60 days');
        self::assertSame($this->store, self::getContainer()->get(BackupLeaseStoreInterface::class));
        self::assertTrue($this->store->acquireLock('plan:'.$plan->id(), new \DateTimeImmutable('+5 minutes')));

        $report = self::getContainer()->get(BackupRetentionRunner::class)->prunePlan($plan->id(), true);

        self::assertSame('busy', $report->status);
        self::assertFileExists($old->archivePath());
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM backup_plan_locks'));
    }

    public function testSwappedAuthenticatedInventoryCannotAuthorizeDeletionForAnotherPlan(): void
    {
        $plan = $this->plan();
        $old = $this->backup($plan, 'old', '-60 days');
        $other = $this->plan('other-plan');
        $otherRun = $this->backup($other, 'other-old', '-60 days');
        $payload = $this->connection->fetchOne('SELECT payload FROM backup_archive_inventory WHERE plan_key = ?', [hash('sha256', $other->id())]);
        $this->connection->update('backup_archive_inventory', ['payload' => $payload], ['plan_key' => hash('sha256', $plan->id())]);

        $report = self::getContainer()->get(BackupRetentionRunner::class)->prunePlan($plan->id(), true);

        self::assertSame('failed', $report->status);
        self::assertSame([], $report->deleted);
        self::assertFileExists($old->archivePath());
        self::assertFileExists($otherRun->archivePath());
        self::assertSame(2, $this->connection->fetchOne('SELECT COUNT(*) FROM backup_archive_inventory'));
    }

    public function testInventoryFailureAfterFileDeletionReportsTheActualCompletedDeletion(): void
    {
        $plan = $this->plan();
        $old = $this->backup($plan, 'old', '-60 days');
        $this->connection->executeStatement("CREATE TRIGGER fail_inventory_delete BEFORE DELETE ON backup_archive_inventory BEGIN SELECT RAISE(FAIL, 'injected inventory failure'); END");

        $report = self::getContainer()->get(BackupRetentionRunner::class)->prunePlan($plan->id(), true);

        self::assertSame('failed', $report->status);
        self::assertSame([$old->archivePath()], $report->deleted);
        self::assertFileDoesNotExist($old->archivePath());
        self::assertCount(1, $this->inventory->forPlan($plan->id()));
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM backup_plan_locks'));
    }

    public function testMissingInventoryMigrationFailsBeforeAnyArchiveIsWritten(): void
    {
        $plan = $this->plan();
        $this->connection->executeStatement('DROP TABLE backup_archive_inventory');
        try {
            $this->expectException(TableNotFoundException::class);
            self::getContainer()->get(BackupAgent::class)->run($plan);
        } finally {
            self::assertDirectoryDoesNotExist($this->workspace.'/archives');
        }
    }

    public function testInventoryMigrationSupportsMysqlAndMissingArchivesFailWithoutDiscardingProvenance(): void
    {
        $schema = new Schema();
        (new Version20261117210000($this->connection, new NullLogger()))->up($schema);
        $sql = implode("\n", $schema->toSql(new MySQLPlatform()));
        self::assertStringContainsString('backup_archive_inventory', $sql);
        self::assertStringContainsString('MEDIUMTEXT', $sql);
        $plan = $this->plan();
        $old = $this->backup($plan, 'old', '-60 days');
        unlink($old->archivePath());

        $report = self::getContainer()->get(BackupRetentionRunner::class)->prunePlan($plan->id(), true);

        self::assertSame('failed', $report->status);
        self::assertSame([], $report->deleted);
        self::assertCount(1, $this->inventory->forPlan($plan->id()));
    }

    public function testOrmSchemaSynchronizationPreservesOnlyTheExplicitDbalManagedTables(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($em);
        $tool->createSchema($metadata);
        $updateSql = implode("\n", $tool->getUpdateSchemaSql($metadata));
        foreach (['backup_plans', 'backup_plan_runs', 'backup_plan_locks', 'backup_archive_inventory'] as $table) {
            self::assertDoesNotMatchRegularExpression('/\b(?:CREATE|ALTER|DROP)\s+TABLE\s+["`]?'.preg_quote($table, '/').'["`]?(?:\s|$)/i', $updateSql);
            self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table));
        }

        // Similar names must remain visible to ORM diffing: the filter is not a
        // blanket exclusion for every table beginning with "backup_".
        $this->connection->executeStatement('CREATE TABLE backup_plans_extra (id INTEGER PRIMARY KEY)');
        $sql = implode("\n", $tool->getUpdateSchemaSql($metadata));
        self::assertStringContainsString('DROP TABLE backup_plans_extra', $sql);
        self::assertStringNotContainsString('DROP TABLE backup_archive_inventory', $sql);
    }

    private function plan(string $id = 'retention-plan', bool $enabled = true): BackupPlan
    {
        $source = $this->workspace.'/source-'.$id;
        mkdir($source, 0700);
        $plan = new BackupPlan($id, 'web', $source, new BackupStorageTarget('local', 'private local target', ['path' => $this->workspace.'/archives']), new RetentionPolicy(2, 30), options: ['retention_enabled' => $enabled, 'compression' => 'gzip', 'encrypt' => true, 'encryption_key' => 'retention-private-key']);
        $this->store->save($plan);

        return $plan;
    }

    private function backup(BackupPlan $plan, string $id, string $age): BackupRun
    {
        file_put_contents($plan->resourceId().'/index.txt', 'archive data '.$id);
        $run = self::getContainer()->get(BackupAgent::class)->run($plan, $id);
        $dated = new BackupRun($run->runId(), $run->planId(), $run->status(), $run->archivePath(), $run->sizeBytes(), $run->checksumSha256(), new \DateTimeImmutable($age));
        $this->inventory->record($plan, $dated);

        return $dated;
    }
}
