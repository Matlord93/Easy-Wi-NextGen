<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Core\Application\Backup\BackupAgent;
use App\Module\Core\Application\Backup\BackupPlan;
use App\Module\Core\Application\Backup\BackupStorageTarget;
use App\Module\Core\Application\Backup\RetentionPolicy;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Musicbot\Application\Backup\MusicbotBackupManifest;
use App\Module\Musicbot\Application\Backup\MusicbotBackupService;
use App\Module\Musicbot\Application\Backup\MusicbotManifestOperations;
use App\Module\Musicbot\Application\Backup\MusicbotManifestOperationsInterface;
use App\Module\Musicbot\Application\Backup\MusicbotRestoreService;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotConnection;
use App\Module\Musicbot\Domain\Enum\MusicbotPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261117210000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MusicbotManifestRestoreTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MusicbotManifestOperations $operations;
    private MusicbotInstance $instance;
    private array $previousDatabase;

    protected function setUp(): void
    {
        $this->previousDatabase = [$_SERVER['DATABASE_URL'] ?? null, $_ENV['DATABASE_URL'] ?? null];
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        require_once dirname(__DIR__, 2).'/migrations/Version20261117210000.php';
        $inventorySchema = new Schema();
        (new Version20261117210000($this->em->getConnection(), new NullLogger()))->up($inventorySchema);
        foreach ($inventorySchema->toSql($this->em->getConnection()->getDatabasePlatform()) as $sql) {
            $this->em->getConnection()->executeStatement($sql);
        }
        $customer = new User('restore@example.test', UserType::Customer);
        $customer->setPasswordHash('test-hash');
        $agent = new Agent('restore-agent', [], 'Restore node');
        $this->instance = new MusicbotInstance($customer, $agent, 'Before', 'musicbot-test', '/srv/musicbot-test');
        foreach ([$customer, $agent, $this->instance] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $operations = self::getContainer()->get(MusicbotManifestOperationsInterface::class);
        self::assertInstanceOf(MusicbotManifestOperations::class, $operations);
        $this->operations = $operations;
    }

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
        parent::tearDown();
    }

    public function testOfficialExportRestoresActualPersistedConfiguration(): void
    {
        $json = $this->operations->exportManifest((string) $this->instance->getId());
        $this->instance->setName('Changed');
        $this->instance->setInstanceConfig(['volume' => 99]);
        $this->em->flush();
        $report = $this->operations->restoreManifest((string) $this->instance->getId(), $json, false);
        self::assertTrue($report->success(), $report->message());
        self::assertSame('Before', $this->em->getConnection()->fetchOne('SELECT name FROM musicbot_instances'));
        self::assertStringContainsString('restored', $report->message());
    }

    public function testRegisteredGenericPipelineRoundTripsEncryptedCompressedConfiguration(): void
    {
        $path = sys_get_temp_dir().'/musicbot-roundtrip-'.bin2hex(random_bytes(10));
        mkdir($path, 0700);
        try {
            $agent = self::getContainer()->get(BackupAgent::class);
            $plan = new BackupPlan('musicbot-roundtrip', 'musicbot', (string) $this->instance->getId(), new BackupStorageTarget('local', 'Test', ['path' => $path]), new RetentionPolicy(1, 1), options: ['compression' => 'gzip', 'encrypt' => true, 'encryption_key' => 'synthetic-test-encryption-key']);
            $stagingBefore = glob(sys_get_temp_dir().'/easywi-backup-snapshot-*');
            $run = $agent->run($plan);
            self::assertSame('succeeded', $run->status());
            self::assertFileExists($run->archivePath());
            self::assertSame($stagingBefore, glob(sys_get_temp_dir().'/easywi-backup-snapshot-*'));
            $this->instance->setName('Changed after backup');
            $this->em->flush();
            $report = $agent->restore($plan, $run->archivePath());
            self::assertTrue($report->success(), $report->message());
            self::assertSame('Before', $this->em->getConnection()->fetchOne('SELECT name FROM musicbot_instances'));
        } finally {
            foreach (glob($path.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($path);
        }
    }

    public function testDryRunDoesNotChangeManagedOrStoredConfiguration(): void
    {
        $json = $this->manifest(['instance' => ['name' => 'After', 'instance_config' => ['volume' => 22]]]);
        $report = $this->operations->restoreManifest((string) $this->instance->getId(), $json, true);
        self::assertTrue($report->success());
        self::assertTrue($report->dryRun());
        self::assertSame('Before', $this->instance->getName());
        self::assertSame('Before', $this->em->getConnection()->fetchOne('SELECT name FROM musicbot_instances'));
    }

    public function testFailureAfterAnEarlierFlushRollsBackAllConfigurationWrites(): void
    {
        $json = $this->manifest([
            'instance' => ['name' => 'Must roll back', 'instance_config' => ['volume' => 22]],
            'plugins' => [['identifier' => 'invalid-plugin', 'name' => 'Invalid plugin', 'permissions' => [123]]],
        ]);
        $report = $this->operations->restoreManifest((string) $this->instance->getId(), $json, false);
        self::assertFalse($report->success());
        self::assertStringContainsString('permissions', $report->message());
        self::assertFalse($this->em->isOpen(), 'A failed transactional restore closes the dirty EntityManager.');
        self::assertSame('Before', $this->em->getConnection()->fetchOne('SELECT name FROM musicbot_instances'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM musicbot_plugins'));
    }

    public function testDryRunRejectsInvalidPluginPermissionsWithoutMutation(): void
    {
        $json = $this->manifest(['instance' => ['name' => 'After'], 'plugins' => [['identifier' => 'bad', 'permissions' => [123]]]]);
        $report = $this->operations->restoreManifest((string) $this->instance->getId(), $json, true);
        self::assertFalse($report->success());
        self::assertStringContainsString('permissions', $report->message());
        self::assertSame('Before', $this->instance->getName());
        self::assertSame('Before', $this->em->getConnection()->fetchOne('SELECT name FROM musicbot_instances'));
    }

    public function testForeignCustomerBackupCannotWriteTheTargetInstance(): void
    {
        $json = $this->manifest(['instance' => ['name' => 'Forbidden']], '99999');
        $report = $this->operations->restoreManifest((string) $this->instance->getId(), $json, false);
        self::assertFalse($report->success());
        self::assertStringContainsString('different customer', $report->message());
        self::assertSame('Before', $this->em->getConnection()->fetchOne('SELECT name FROM musicbot_instances'));
    }

    public function testTamperedManifestCannotWriteData(): void
    {
        $json = $this->manifest(['instance' => ['name' => 'After']]);
        $report = $this->operations->restoreManifest((string) $this->instance->getId(), str_replace('After', 'Tampered', $json), false);
        self::assertFalse($report->success());
        self::assertStringContainsString('checksum', $report->message());
        self::assertSame('Before', $this->em->getConnection()->fetchOne('SELECT name FROM musicbot_instances'));
    }

    public function testDifferentInstanceManifestCannotWriteData(): void
    {
        $json = $this->manifest(['instance' => ['name' => 'Forbidden']], instanceId: '99999');
        $report = $this->operations->restoreManifest((string) $this->instance->getId(), $json, false);
        self::assertFalse($report->success());
        self::assertStringContainsString('different instance', $report->message());
        self::assertSame('Before', $this->em->getConnection()->fetchOne('SELECT name FROM musicbot_instances'));
    }

    public function testUnsupportedSectionsCannotBeReportedAsRestored(): void
    {
        $json = $this->manifest(['instance' => ['name' => 'Forbidden'], 'tracks' => []]);
        $report = $this->operations->restoreManifest((string) $this->instance->getId(), $json, false);
        self::assertFalse($report->success());
        self::assertStringContainsString('cannot restore sections: tracks', $report->message());
        self::assertSame('Before', $this->em->getConnection()->fetchOne('SELECT name FROM musicbot_instances'));
    }

    public function testPartialConfigurationWarningsAreRetained(): void
    {
        $report = $this->operations->restoreManifest((string) $this->instance->getId(), $this->manifest(['playlists' => [['name' => '']]]), false);
        self::assertTrue($report->success());
        self::assertStringContainsString('Incomplete items: Skipped playlist with empty name.', $report->message());
    }

    public function testMaskedBackupPreservesExistingEncryptedConnectionSecrets(): void
    {
        $encryptedSecrets = ['bot_token' => 'v1:synthetic-encrypted-test-credential'];
        $connection = new MusicbotConnection($this->instance, MusicbotPlatform::Discord, ['client_id' => 'original-client'], $encryptedSecrets);
        $this->em->persist($connection);
        $this->em->flush();
        $json = $this->operations->exportManifest((string) $this->instance->getId());
        self::assertStringNotContainsString($encryptedSecrets['bot_token'], $json);
        $connection->setConnectionConfig(['client_id' => 'changed-client']);
        $this->em->flush();

        $report = $this->operations->restoreManifest((string) $this->instance->getId(), $json, false);

        self::assertTrue($report->success(), $report->message());
        self::assertSame($encryptedSecrets, $connection->getSecretConfig());
        self::assertSame(['client_id' => 'original-client'], $connection->getConnectionConfig());
        self::assertSame($encryptedSecrets, json_decode((string) $this->em->getConnection()->fetchOne('SELECT secret_config FROM musicbot_connections'), true, flags: JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($encryptedSecrets['bot_token'], $report->message());
    }

    public function testMissingMaskedConnectionRequiresCredentialsBeforeAnyWrites(): void
    {
        $json = $this->manifest([
            'instance' => ['name' => 'Must stay unchanged'],
            'connections' => [['platform' => 'discord', 'enabled' => true, 'secret_config' => ['bot_token' => '********']]],
        ]);
        foreach ([true, false] as $dryRun) {
            $report = $this->operations->restoreManifest((string) $this->instance->getId(), $json, $dryRun);
            self::assertFalse($report->success());
            self::assertSame($dryRun, $report->dryRun());
            self::assertStringContainsString('connection credentials required', $report->message());
            self::assertStringContainsString('No restore was performed', $report->message());
            self::assertStringNotContainsString('********', $report->message());
            self::assertSame('Before', $this->instance->getName());
            self::assertSame('Before', $this->em->getConnection()->fetchOne('SELECT name FROM musicbot_instances'));
            self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM musicbot_connections'));
            self::assertTrue($this->em->isOpen());
        }
    }

    private function manifest(array $data, ?string $customerId = null, ?string $instanceId = null): string
    {
        $manifest = new MusicbotBackupManifest('1', 'customer', $instanceId ?? (string) $this->instance->getId(), $customerId ?? (string) $this->instance->getCustomer()->getId(), 'musicbot-test', 'test', new \DateTimeImmutable(), $data);
        $payload = $manifest->toArray();
        unset($payload['checksum']);
        $manifest = new MusicbotBackupManifest($manifest->schemaVersion, $manifest->backupType, $manifest->instanceId, $manifest->customerId, $manifest->serviceName, $manifest->appVersion, $manifest->createdAt, $data, hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)));

        return json_encode($manifest->toArray(), JSON_THROW_ON_ERROR);
    }
}
