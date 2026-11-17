<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Core\Application\Backup\BackupAgent;
use App\Module\Core\Application\Backup\BackupPlan;
use App\Module\Core\Application\Backup\BackupStorageTarget;
use App\Module\Core\Application\Backup\RetentionPolicy;
use App\Module\Core\Application\Backup\WritableBackupPlanStoreInterface;
use App\Module\Core\Command\RestoreBackupPlanCommand;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261117200000;
use DoctrineMigrations\Version20261117210000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class RestoreBackupPlanCommandTest extends KernelTestCase
{
    private array $previousDatabase;
    private string $workspace;

    #[\Override]
    protected function setUp(): void
    {
        $this->previousDatabase = [$_SERVER['DATABASE_URL'] ?? null, $_ENV['DATABASE_URL'] ?? null];
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        $this->workspace = sys_get_temp_dir().'/restore-plan-command-'.bin2hex(random_bytes(8));
        mkdir($this->workspace, 0700);
        self::bootKernel();
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        require_once dirname(__DIR__, 2).'/migrations/Version20261117200000.php';
        require_once dirname(__DIR__, 2).'/migrations/Version20261117210000.php';
        $schema = new Schema();
        (new Version20261117200000($connection, new NullLogger()))->up($schema);
        (new Version20261117210000($connection, new NullLogger()))->up($schema);
        foreach ($schema->toSql($connection->getDatabasePlatform()) as $sql) {
            $connection->executeStatement($sql);
        }
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

    public function testActualEncryptedGzipBackupCanBeValidatedThenRestoredThroughCommand(): void
    {
        $store = self::getContainer()->get(WritableBackupPlanStoreInterface::class);
        $plan = $this->plan('web');
        $store->save($plan);
        mkdir($plan->resourceId());
        file_put_contents($plan->resourceId().'/index.txt', "original\0binary");
        $run = self::getContainer()->get(BackupAgent::class)->run($plan);
        (new Filesystem())->remove($plan->resourceId());

        $command = new CommandTester(self::getContainer()->get(RestoreBackupPlanCommand::class));
        self::assertSame(0, $command->execute(['plan' => $plan->id(), 'archive' => $run->archivePath()]));
        self::assertDirectoryDoesNotExist($plan->resourceId());
        self::assertStringContainsString('Validation only', $command->getDisplay());
        self::assertSame(0, $command->execute(['plan' => $plan->id(), 'archive' => $run->archivePath(), '--apply' => true]));
        self::assertSame("original\0binary", file_get_contents($plan->resourceId().'/index.txt'));
        self::assertStringContainsString('Restore completed', $command->getDisplay());
        self::assertStringNotContainsString('private-test-archive-key', $command->getDisplay());
        self::assertSame(0, self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT COUNT(*) FROM backup_plan_locks'));
    }

    public function testBusyPlanCannotBeRestoredOrHaveItsOtherLeaseReleased(): void
    {
        $store = self::getContainer()->get(WritableBackupPlanStoreInterface::class);
        $plan = $this->plan('web');
        $store->save($plan);
        self::assertTrue($store->acquireLock('plan:'.$plan->id(), new \DateTimeImmutable('+5 minutes')));
        $command = new CommandTester(self::getContainer()->get(RestoreBackupPlanCommand::class));

        self::assertSame(1, $command->execute(['plan' => $plan->id(), 'archive' => $this->workspace.'/absent', '--apply' => true]));
        self::assertStringContainsString('Backup plan is busy', $command->getDisplay());
        self::assertSame(1, self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT COUNT(*) FROM backup_plan_locks'));
        self::assertDirectoryDoesNotExist($plan->resourceId());
    }

    public function testInvalidArchiveFailsAndReleasesThePlanLock(): void
    {
        $store = self::getContainer()->get(WritableBackupPlanStoreInterface::class);
        $plan = $this->plan('web');
        $store->save($plan);
        $command = new CommandTester(self::getContainer()->get(RestoreBackupPlanCommand::class));

        self::assertSame(1, $command->execute(['plan' => $plan->id(), 'archive' => $this->workspace.'/absent', '--apply' => true]));
        self::assertDirectoryDoesNotExist($plan->resourceId());
        self::assertTrue($store->acquireLock('plan:'.$plan->id(), new \DateTimeImmutable('+5 minutes')));
        self::assertStringNotContainsString('private-test-archive-key', $command->getDisplay());
    }

    public function testMissingPlanAndInvalidPersistedResourceCannotReportSuccess(): void
    {
        $command = new CommandTester(self::getContainer()->get(RestoreBackupPlanCommand::class));
        self::assertSame(2, $command->execute(['plan' => 'absent', 'archive' => $this->workspace.'/absent']));
        $plan = $this->plan('mail', []);
        self::getContainer()->get(WritableBackupPlanStoreInterface::class)->save($plan);
        file_put_contents($this->workspace.'/archive.tar', 'placeholder');

        self::assertSame(1, $command->execute(['plan' => $plan->id(), 'archive' => $this->workspace.'/archive.tar', '--apply' => true]));
        self::assertStringContainsString('Module restore failed. Verify resource binding', $command->getDisplay());
        self::assertSame(0, self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT COUNT(*) FROM backup_plan_locks'));
        self::assertStringNotContainsString('Restore completed', $command->getDisplay());
        self::assertDirectoryDoesNotExist($plan->resourceId());
    }

    private function plan(string $module, array $options = ['compression' => 'gzip', 'encrypt' => true, 'encryption_key' => 'private-test-archive-key']): BackupPlan
    {
        return new BackupPlan('command-plan', $module, $this->workspace.'/restored', new BackupStorageTarget('local', 'test', ['base_path' => $this->workspace.'/archives']), new RetentionPolicy(1, 1), null, 'UTC', $options);
    }
}
