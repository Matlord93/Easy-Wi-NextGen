<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Core\Application\Backup\BackupPlanStoreInterface;
use App\Module\Core\Application\Backup\DbalBackupPlanStore;
use App\Module\Core\Application\Backup\WritableBackupPlanStoreInterface;
use App\Module\Core\Command\DispatchBackupSchedulesCommand;
use App\Module\Core\Command\ImportBackupPlanCommand;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261117200000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class PersistentBackupPlansTest extends KernelTestCase
{
    private array $previousDatabase;
    private string $file;

    #[\Override]
    protected function setUp(): void
    {
        $this->previousDatabase = [$_SERVER['DATABASE_URL'] ?? null, $_ENV['DATABASE_URL'] ?? null];
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        $file = tempnam(sys_get_temp_dir(), 'plan-import-');
        self::assertIsString($file);
        $this->file = $file;
        file_put_contents($file, json_encode([
            'version' => 1, 'id' => 'kernel-import', 'module' => 'web', 'resource_id' => '/srv/website',
            'target' => ['type' => 'local', 'label' => 'Backup', 'config' => ['base_path' => '/srv/backups'], 'secrets' => ['password' => 'never-display-this']],
            'retention' => ['keep_count' => 7, 'keep_days' => 30], 'timezone' => 'UTC', 'cron' => null, 'options' => [],
        ], JSON_THROW_ON_ERROR));
        self::bootKernel();
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
        unlink($this->file);
        parent::tearDown();
    }

    public function testRealContainerUsesDurableStoreAndImportedPlanIsVisibleToScheduler(): void
    {
        $container = self::getContainer();
        $connection = $container->get(EntityManagerInterface::class)->getConnection();
        require_once dirname(__DIR__, 2).'/migrations/Version20261117200000.php';
        $schema = new Schema();
        (new Version20261117200000($connection, new NullLogger()))->up($schema);
        foreach ($schema->toSql($connection->getDatabasePlatform()) as $sql) {
            $connection->executeStatement($sql);
        }
        $store = $container->get(BackupPlanStoreInterface::class);
        self::assertInstanceOf(DbalBackupPlanStore::class, $store);
        self::assertSame($store, $container->get(WritableBackupPlanStoreInterface::class));
        $import = new CommandTester($container->get(ImportBackupPlanCommand::class));
        self::assertSame(0, $import->execute(['file' => $this->file]));
        self::assertSame('kernel-import', $store->find('kernel-import')?->id());
        self::assertStringNotContainsString('never-display-this', $import->getDisplay());
        $dispatch = new CommandTester($container->get(DispatchBackupSchedulesCommand::class));
        self::assertSame(0, $dispatch->execute([]));
        self::assertStringContainsString('Dispatched 0 backup schedule(s).', $dispatch->getDisplay());
        self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM backup_plans'));
    }

    public function testMissingMigrationFailsExplicitlyWithoutInMemoryFallbackOrSecretOutput(): void
    {
        $import = new CommandTester(self::getContainer()->get(ImportBackupPlanCommand::class));
        self::assertSame(1, $import->execute(['file' => $this->file]));
        self::assertStringContainsString('Check database migrations', $import->getDisplay());
        self::assertStringNotContainsString('never-display-this', $import->getDisplay());
    }
}
