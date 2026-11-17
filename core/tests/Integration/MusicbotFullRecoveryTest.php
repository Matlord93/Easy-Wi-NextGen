<?php

declare(strict_types=1);
namespace App\Tests\Integration;

use App\Module\Core\Application\Backup\Adapter\RestoreReport;
use App\Module\Core\Application\Backup\BackupTemporaryDirectory;
use App\Module\Core\Application\Backup\ModuleArchiveGatewayInterface;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Musicbot\Application\Backup\MusicbotFullBackupAdapter;
use App\Module\Musicbot\Application\Backup\MusicbotFullBackupOperations;
use App\Module\Musicbot\Application\Backup\MusicbotFullState;
use App\Module\Musicbot\Domain\Entity\MusicbotConnection;
use App\Module\Musicbot\Domain\Entity\MusicbotCustomerLimits;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotPlaylist;
use App\Module\Musicbot\Domain\Entity\MusicbotPlaylistItem;
use App\Module\Musicbot\Domain\Entity\MusicbotPluginLog;
use App\Module\Musicbot\Domain\Entity\MusicbotQueueItem;
use App\Module\Musicbot\Domain\Entity\MusicbotTrack;
use App\Module\Musicbot\Domain\Enum\MusicbotPlatform;
use App\Module\Musicbot\Domain\Enum\MusicbotTrackSourceType;
use App\Repository\MusicbotInstanceRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MusicbotFullRecoveryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MusicbotInstance $instance;
    private MusicbotFullBackupAdapter $adapter;
    private MusicbotRecoveryFixtureGateway $gateway;
    private string $directory;
    private array $previousDatabase;

    protected function setUp(): void
    {
        $this->previousDatabase = [$_SERVER['DATABASE_URL'] ?? null, $_ENV['DATABASE_URL'] ?? null];
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->directory = BackupTemporaryDirectory::create('easywi-musicbot-test-');
        $customer = new User('musicbot-full@example.test', UserType::Customer);
        $customer->setPasswordHash('test');
        $agent = new Agent('musicbot-full', [], 'Full restore test');
        $this->instance = new MusicbotInstance($customer, $agent, 'Original', 'musicbot-test', '/srv/musicbot-test');
        $this->instance->setInstanceConfig(['volume' => 42, 'runtime_control_token' => 'must-not-export', 'youtube' => ['history' => [['type' => 'import']]]]);
        $track = new MusicbotTrack($customer, 'Original track', MusicbotTrackSourceType::Upload, 'audio/mpeg', hash('sha256', 'real-media-bytes'));
        $track->setInstance($this->instance);
        $track->setFilePath('/srv/musicbot-test/media/song.mp3');
        $playlist = new MusicbotPlaylist($customer, 'Original playlist', $this->instance);
        $connection = new MusicbotConnection($this->instance, MusicbotPlatform::Discord, ['client_id' => 'saved'], ['bot_token' => (new SecretsCrypto('musicbot-test-key'))->encrypt('private-bot-token')]);
        $limits = new MusicbotCustomerLimits($customer);
        $limits->setMaxTracks(10);
        foreach ([$customer, $agent, $this->instance, $track, $playlist, $connection, $limits] as $entity) { $this->em->persist($entity); }
        $this->em->flush();
        foreach ([new MusicbotPlaylistItem($playlist, $track, 0), new MusicbotQueueItem($this->instance, $track, 0), new MusicbotPluginLog($this->instance, 'test-plugin', 'play', null, 'ok', 'Saved log', ['key' => 'saved'])] as $entity) { $this->em->persist($entity); }
        $this->em->flush();
        $this->gateway = new MusicbotRecoveryFixtureGateway($this->directory);
        $operations = new MusicbotFullBackupOperations(self::getContainer()->get(MusicbotInstanceRepositoryInterface::class), new MusicbotFullState($this->em->getConnection()), $this->gateway, new SecretsCrypto('musicbot-test-key'), $this->em);
        $this->adapter = new MusicbotFullBackupAdapter($operations);
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        if (isset($this->directory)) { BackupTemporaryDirectory::remove($this->directory, 'easywi-musicbot-test-'); }
        foreach (['SERVER', 'ENV'] as $index => $global) {
            if ($this->previousDatabase[$index] === null) { unset($GLOBALS['_'.$global]['DATABASE_URL']); }
            else { $GLOBALS['_'.$global]['DATABASE_URL'] = $this->previousDatabase[$index]; }
        }
        parent::tearDown();
    }

    public function testActualFullArchiveRestoresMediaAndRelationalMetadata(): void
    {
        $archive = $this->archive();
        $db = $this->em->getConnection();
        $db->executeStatement("UPDATE musicbot_instances SET name = 'Changed'");
        $db->executeStatement("UPDATE musicbot_tracks SET title = 'Changed track'");
        foreach (['musicbot_playlist_items', 'musicbot_queue_items', 'musicbot_plugin_logs'] as $table) { $db->executeStatement('DELETE FROM '.$table); }
        $db->executeStatement("UPDATE musicbot_connections SET secret_config = '{}'");
        $db->executeStatement('UPDATE musicbot_customer_limits SET max_tracks = 99');
        $this->gateway->media = 'changed-media';
        $report = $this->adapter->restore((string) $this->instance->getId(), $archive);
        self::assertTrue($report->success(), $report->message());
        self::assertSame('Original', $db->fetchOne('SELECT name FROM musicbot_instances'));
        self::assertSame('Original track', $db->fetchOne('SELECT title FROM musicbot_tracks'));
        foreach (['musicbot_playlist_items', 'musicbot_queue_items', 'musicbot_plugin_logs'] as $table) { self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM '.$table)); }
        $secrets = json_decode((string) $db->fetchOne('SELECT secret_config FROM musicbot_connections'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('private-bot-token', (new SecretsCrypto('musicbot-test-key'))->decrypt($secrets['bot_token']));
        self::assertSame('real-media-bytes', $this->gateway->media);
        self::assertSame(10, (int) $db->fetchOne('SELECT max_tracks FROM musicbot_customer_limits'));
        $config = json_decode((string) $db->fetchOne('SELECT instance_config FROM musicbot_instances'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('must-not-export', $config['runtime_control_token']);
        self::assertSame('import', $config['youtube']['history'][0]['type']);
    }

    public function testDryRunPreflightsActualAssetsWithoutMutation(): void
    {
        $archive = $this->archive();
        $this->gateway->media = 'current-media';
        $this->em->getConnection()->executeStatement("UPDATE musicbot_instances SET name = 'Current'");
        $report = $this->adapter->restore((string) $this->instance->getId(), $archive, true);
        self::assertTrue($report->success(), $report->message());
        self::assertSame('Current', $this->em->getConnection()->fetchOne('SELECT name FROM musicbot_instances'));
        self::assertSame('current-media', $this->gateway->media);
        self::assertSame(0, $this->gateway->applies);
    }

    public function testInterruptedApplyRollsBackDatabaseAndPriorMedia(): void
    {
        $archive = $this->archive();
        $this->gateway->media = 'previous-media';
        $this->gateway->failNextApply = true;
        $this->em->getConnection()->executeStatement("UPDATE musicbot_instances SET name = 'Previous'");
        $report = $this->adapter->restore((string) $this->instance->getId(), $archive);
        self::assertFalse($report->success());
        self::assertSame('Previous', $this->em->getConnection()->fetchOne('SELECT name FROM musicbot_instances'));
        self::assertSame('previous-media', $this->gateway->media);
        self::assertSame(2, $this->gateway->applies);
    }

    public function testTamperedEncryptedMetadataCannotApplyOrWrite(): void
    {
        $snapshot = $this->adapter->snapshot((string) $this->instance->getId());
        try {
            $json = file_get_contents($snapshot->sourcePath().'/full.json');
            self::assertStringNotContainsString('private-bot-token', $json);
            self::assertStringNotContainsString('must-not-export', $json);
            $envelope = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            $envelope['encrypted_state'] .= 'tampered';
            file_put_contents($snapshot->sourcePath().'/full.json', json_encode($envelope, JSON_THROW_ON_ERROR));
            $archive = $this->directory.'/tampered.tar';
            (new \PharData($archive))->buildFromDirectory($snapshot->sourcePath());
            $report = $this->adapter->restore((string) $this->instance->getId(), $archive);
            self::assertFalse($report->success());
            self::assertSame(0, $this->gateway->applies);
            self::assertSame('Original', $this->em->getConnection()->fetchOne('SELECT name FROM musicbot_instances'));
        } finally { $snapshot->cleanup(); }
    }

    public function testMissingCataloguedMediaFailsBeforePublishingSnapshot(): void
    {
        $this->gateway->media = 'corrupted-media';
        $before = glob(sys_get_temp_dir().'/easywi-backup-snapshot-*');
        try {
            $this->adapter->snapshot((string) $this->instance->getId());
            self::fail('Corrupted media must not produce a successful snapshot.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('checksum', $error->getMessage());
        }
        self::assertSame($before, glob(sys_get_temp_dir().'/easywi-backup-snapshot-*'));
    }

    public function testForeignInstanceCannotRestoreEncryptedArchive(): void
    {
        $archive = $this->archive();
        $other = new MusicbotInstance($this->instance->getCustomer(), $this->instance->getNode(), 'Other', 'musicbot-other', '/srv/musicbot-other');
        $this->em->persist($other);
        $this->em->flush();
        $report = $this->adapter->restore((string) $other->getId(), $archive);
        self::assertFalse($report->success());
        self::assertStringContainsString('another instance', $report->message());
        self::assertSame(0, $this->gateway->applies);
    }

    public function testForeignRowAndSchemaChangesAreRejectedBeforeAgentApply(): void
    {
        $state = new MusicbotFullState($this->em->getConnection());
        $data = $state->export($this->instance);
        $data['tables']['musicbot_tracks'][0]['customer_id'] = 999999;
        try {
            $state->validate($this->instance, $data);
            self::fail('Foreign customers must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('different customer', $error->getMessage());
        }
        $data = $state->export($this->instance);
        $data['tables']['musicbot_tracks'][0]['unrecognized_column'] = 'value';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('schema mismatch');
        $state->validate($this->instance, $data);
    }

    public function testCrossInstanceReferencePreventsCascadingDeletion(): void
    {
        $other = new MusicbotInstance($this->instance->getCustomer(), $this->instance->getNode(), 'Other', 'musicbot-other', '/srv/musicbot-other');
        $playlist = new MusicbotPlaylist($this->instance->getCustomer(), 'Shared track reference', $other);
        $this->em->persist($other);
        $this->em->persist($playlist);
        $this->em->flush();
        $track = $this->em->getRepository(MusicbotTrack::class)->findOneBy(['instance' => $this->instance]);
        $item = new MusicbotPlaylistItem($playlist, $track, 0);
        $this->em->persist($item);
        $this->em->flush();
        $state = new MusicbotFullState($this->em->getConnection());
        try {
            $state->validate($this->instance, $state->export($this->instance));
            self::fail('Recovery must reject cross-instance references before replacing metadata.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('shared with another instance', $error->getMessage());
        }
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM musicbot_playlist_items'));
    }

    public function testRollbackFailureRetainsPrivateRecoveryArchiveAndReportsFailure(): void
    {
        $archive = $this->archive();
        $this->gateway->media = 'prior-media';
        $this->gateway->failAllApplies = true;
        $this->em->getConnection()->executeStatement("UPDATE musicbot_instances SET name = 'Prior'");
        $report = $this->adapter->restore((string) $this->instance->getId(), $archive);
        self::assertFalse($report->success());
        self::assertStringContainsString('rollback could not complete', $report->message());
        self::assertSame(1, preg_match('~Recovery archive retained at (.+/assets[.]tar[.]gz)[.]~', $report->message(), $matches));
        $recovery = dirname($matches[1]);
        try {
            self::assertTrue(BackupTemporaryDirectory::isOwned($recovery, 'easywi-musicbot-recovery-'));
            self::assertSame(0, fileperms($recovery) & 0077);
            self::assertFileExists($matches[1]);
            self::assertSame('Prior', $this->em->getConnection()->fetchOne('SELECT name FROM musicbot_instances'));
        } finally { BackupTemporaryDirectory::remove($recovery, 'easywi-musicbot-recovery-'); }
    }

    public function testUnavailableCredentialKeyFailsBeforeSnapshot(): void
    {
        $encrypted = (new SecretsCrypto('unavailable-installation-key'))->encrypt('other-secret');
        $this->em->getConnection()->update('musicbot_connections', ['secret_config' => json_encode(['bot_token' => $encrypted], JSON_THROW_ON_ERROR)], ['musicbot_instance_id' => $this->instance->getId()]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be decrypted');
        $this->adapter->snapshot((string) $this->instance->getId());
    }

    private function archive(): string
    {
        $snapshot = $this->adapter->snapshot((string) $this->instance->getId());
        try {
            $archive = $this->directory.'/full.tar';
            (new \PharData($archive))->buildFromDirectory($snapshot->sourcePath());
            return $archive;
        } finally { $snapshot->cleanup(); }
    }
}

final class MusicbotRecoveryFixtureGateway implements ModuleArchiveGatewayInterface
{
    public string $media = 'real-media-bytes';
    public int $applies = 0;
    public bool $failNextApply = false;
    public bool $failAllApplies = false;
    public function __construct(private readonly string $directory) {}
    public function snapshot(Agent $agent, string $customerId, array $descriptor, string $destinationArchive): void
    {
        $tar = $this->directory.'/agent-'.bin2hex(random_bytes(5)).'.tar';
        $archive = new \PharData($tar);
        $archive->addFromString('.module-backup.json', json_encode(['version' => 1, 'module' => $descriptor['module'], 'resource_id' => $descriptor['resource_id'], 'customer_id' => $customerId, 'roots' => ['primary']], JSON_THROW_ON_ERROR));
        $archive->addFromString('files/primary/media/song.mp3', $this->media);
        $archive->compress(\Phar::GZ);
        copy($tar.'.gz', $destinationArchive);
    }
    public function restore(Agent $agent, string $customerId, array $descriptor, string $archivePath, bool $dryRun): RestoreReport
    {
        $archive = new \PharData($archivePath);
        $media = $archive['files/primary/media/song.mp3']->getContent();
        if (!$dryRun) {
            ++$this->applies;
            $this->media = $media;
            if ($this->failAllApplies) { throw new \RuntimeException('Simulated unreachable response for apply and rollback.'); }
            if ($this->failNextApply) { $this->failNextApply = false; throw new \RuntimeException('Response lost after apply.'); }
        }
        return new RestoreReport($dryRun, true, 'Completed actual fixture archive operation.');
    }
}
