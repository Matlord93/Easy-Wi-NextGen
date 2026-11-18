<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Core\Application\Backup\Adapter\ModuleBackupOperationsRegistry;
use App\Module\Core\Application\Backup\Adapter\RestoreReport;
use App\Module\Core\Application\Backup\BackupTemporaryDirectory;
use App\Module\Core\Application\Backup\ModuleArchiveGatewayInterface;
use App\Module\Core\Application\Backup\Resource\ModuleBackupBindingStore;
use App\Module\Core\Application\Backup\Resource\ModuleBackupResourceResolver;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Application\EncryptionServiceInterface;
use App\Module\Core\Command\BindModuleBackupCommand;
use App\Module\Core\Domain\Entity\{Agent, Database, DatabaseNode, Domain, Instance, Mailbox, MailDomain, MailNode, Template, Ts3Instance, Ts6Instance, User};
use App\Module\Core\Domain\Enum\{InstanceStatus, Ts3DatabaseMode, Ts3InstanceStatus, Ts6InstanceStatus, UserType};
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DoctrineMigrations\Version20261118010000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ManagedModuleBackupProvidersTest extends KernelTestCase
{
    public function testRealProviderRegistrationEntityResolutionFullRoundTripAndMailRollback(): void
    {
        $previous = [$_SERVER['DATABASE_URL'] ?? null, $_ENV['DATABASE_URL'] ?? null];
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        $directory = BackupTemporaryDirectory::create('easywi-backup-snapshot-');
        try {
            self::bootKernel();
            $container = self::getContainer();
            $em = $container->get(EntityManagerInterface::class);
            (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
            require_once dirname(__DIR__, 2).'/migrations/Version20261118010000.php';
            $schema = new Schema();
            $connection = $em->getConnection();
            (new Version20261118010000($connection, new NullLogger()))->up($schema);
            foreach ($schema->toSql($connection->getDatabasePlatform()) as $sql) {
                $connection->executeStatement($sql);
            }
            $encryption = new EncryptionService('test', 'test:'.base64_encode(str_repeat('x', 32)));
            $container->set(EncryptionServiceInterface::class, $encryption);
            $user = new User('backup@example.test', UserType::Customer);
            $user->setPasswordHash('unused-test-password');
            $agent = new Agent('managed-backup-node', $encryption->encrypt('agent-secret'));
            $node = new DatabaseNode('DB', 'mariadb', 'database.internal', 3310, $agent);
            $database = new Database($user, 'mariadb', 'database.internal', 3310, 'customer_database', 'customer_user', $encryption->encrypt('database-password'), $node);
            $voice = new Ts6Instance($user, $agent, 'Voice 6', Ts6InstanceStatus::Stopped);
            $voice->setInstallPath('/opt/easywi/ts6/1');
            $voice3 = new Ts3Instance($user, $agent, 'Voice 3', 9987, 10011, 30033, Ts3DatabaseMode::Mysql, 'ts3-db.internal', 3306, 'ts3db', 'ts3user', $encryption->encrypt('voice-database-password'), Ts3InstanceStatus::Stopped);
            $voice3->setInstallPath('/opt/easywi/ts3/1');
            $template = new Template('test-game', 'Test game', null, null, null, [], '', [], [], [], [], '', '', [], []);
            $game = new Instance($user, $template, $agent, 1, 1024, 10, null, InstanceStatus::Stopped);
            $game->setInstallPath('/home/easywi/game/1');
            $domain = new Domain($user, null, 'example.test');
            $mailNode = new MailNode('Mail', 'imap.external.test', 993, 'smtp.external.test', 465, null);
            $mail = new MailDomain($domain, $mailNode);
            $mailbox = new Mailbox($domain, 'alice', 'original-password-hash', $encryption->encrypt('mail-password'), 1024);
            foreach ([$user, $agent, $node, $database, $voice, $voice3, $template, $game, $domain, $mailNode, $mail, $mailbox] as $entity) {
                $em->persist($entity);
            }
            $em->flush();
            $ids = ['game' => (string) $game->getId(), 'db' => (string) $database->getId(), 'voice' => 'ts6:'.$voice->getId(), 'ts3' => 'ts3:'.$voice3->getId(), 'mail' => (string) $mail->getId()];
            $bindings = $container->get(ModuleBackupBindingStore::class);
            foreach (['game', 'voice', 'mail', 'ts3'] as $module) {
                $binding = ['agent_id' => $agent->getId(), 'service' => 'managed-'.$module.'.service'];
                if ($module === 'mail') {
                    $binding = ['agent_id' => $agent->getId(), 'path' => '/var/vmail/example.test', 'services' => ['postfix.service', 'dovecot.service'], 'additional_paths' => ['config' => '/etc/easywi/mail/example.test', 'dkim' => '/etc/opendkim/keys/example.test']];
                }
                $bindings->save($module === 'ts3' ? 'voice' : $module, $ids[$module], $binding);
            }
            $snapshots = [];
            $restoreSucceeds = true;
            $gateway = $this->createStub(ModuleArchiveGatewayInterface::class);
            $gateway->method('snapshot')->willReturnCallback(function (Agent $agent, string $customer, array $descriptor, string $destination) use (&$snapshots): void {
                self::assertSame('managed-backup-node', $agent->getId());
                self::assertNotSame('', $customer);
                $snapshots[] = $descriptor;
                file_put_contents($destination, 'actual-'.$descriptor['module'].'-'.$descriptor['resource_id']);
            });
            $gateway->method('restore')->willReturnCallback(function (Agent $agent, string $customer, array $descriptor, string $archive, bool $dryRun) use (&$restoreSucceeds): RestoreReport {
                self::assertSame('actual-'.$descriptor['module'].'-'.$descriptor['resource_id'], file_get_contents($archive));
                return new RestoreReport($dryRun, $restoreSucceeds, $restoreSucceeds ? 'Completed by Agent.' : 'Agent failed.');
            });
            $container->set(ModuleArchiveGatewayInterface::class, $gateway);
            $registry = $container->get(ModuleBackupOperationsRegistry::class);
            foreach (['game', 'db', 'voice', 'mail', 'ts3'] as $module) {
                $provider = $registry->find($module === 'ts3' ? 'voice' : $module);
                self::assertNotNull($provider);
                $snapshot = $provider->snapshot($ids[$module]);
                (new \PharData($directory.'/'.$module.'.tar'))->buildFromDirectory($snapshot->sourcePath());
                $snapshot->cleanup();
                self::assertTrue($provider->restore($ids[$module], $directory.'/'.$module.'.tar', true)->success(), $module.' dry-run');
                self::assertTrue($provider->restore($ids[$module], $directory.'/'.$module.'.tar', false)->success(), $module.' restore');
            }
            self::assertSame('database-password', $snapshots[1]['database']['password']);
            self::assertSame(3310, $snapshots[1]['database']['port']);
            self::assertSame('disabled', $snapshots[1]['database']['tls_mode']);
            self::assertSame('voice-database-password', $snapshots[4]['database']['password']);
            self::assertArrayHasKey('path', $snapshots[4]);
            self::assertSame('disabled', $snapshots[4]['database']['tls_mode']);
            $resolver = $container->get(ModuleBackupResourceResolver::class);
            foreach (['off' => 'disabled', 'required' => 'required', 'verify_ca' => 'verify_ca', 'verify_full' => 'verify_identity'] as $nodeMode => $agentMode) {
                $node->setTlsMode($nodeMode);
                self::assertSame($agentMode, $resolver->resolve('db', $ids['db'])->descriptor['database']['tls_mode']);
            }
            // Legacy/corrupt persisted values must fail, never downgrade verification.
            (new \ReflectionProperty(DatabaseNode::class, 'tlsMode'))->setValue($node, 'unsupported-legacy-mode');
            $rejectedTlsMode = false;
            try {
                $resolver->resolve('db', $ids['db']);
            } catch (\InvalidArgumentException) {
                $rejectedTlsMode = true;
            }
            self::assertTrue($rejectedTlsMode);
            $node->setTlsMode('off');
            $provider = $registry->find('mail');
            $connection->executeStatement("UPDATE mailboxes SET password_hash = 'current-password-hash'");
            $restoreSucceeds = false;
            self::assertFalse($provider->restore($ids['mail'], $directory.'/mail.tar', false)->success());
            self::assertSame('current-password-hash', $connection->fetchOne('SELECT password_hash FROM mailboxes'));
            $restoreSucceeds = true;
            self::assertTrue($provider->restore($ids['mail'], $directory.'/mail.tar', false)->success());
            self::assertSame('original-password-hash', $connection->fetchOne('SELECT password_hash FROM mailboxes'));
            $file = $directory.'/binding.json';
            file_put_contents($file, json_encode(['agent_id' => 'different-agent', 'path' => '/home/wrong', 'service' => 'wrong.service'], JSON_THROW_ON_ERROR));
            chmod($file, 0600);
            $command = new CommandTester($container->get(BindModuleBackupCommand::class));
            self::assertSame(1, $command->execute(['module' => 'game', 'resource' => $ids['game'], 'file' => $file]));
            self::assertSame('/home/easywi/game/1', $container->get(ModuleBackupResourceResolver::class)->resolve('game', $ids['game'])->descriptor['path']);
        } finally {
            BackupTemporaryDirectory::remove($directory, 'easywi-backup-snapshot-');
            self::ensureKernelShutdown();
            foreach (['SERVER', 'ENV'] as $index => $global) {
                if ($previous[$index] === null) {
                    unset($GLOBALS['_'.$global]['DATABASE_URL']);
                } else {
                    $GLOBALS['_'.$global]['DATABASE_URL'] = $previous[$index];
                }
            }
        }
    }
}
