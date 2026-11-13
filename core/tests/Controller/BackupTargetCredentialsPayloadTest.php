<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\Backup;
use App\Module\Core\Domain\Entity\BackupDefinition;
use App\Module\Core\Domain\Entity\BackupTarget;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\BackupDestinationType;
use App\Module\Core\Domain\Enum\BackupStatus;
use App\Module\Core\Domain\Enum\BackupTargetType;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\UI\Controller\Customer\CustomerInstanceActionApiController;
use App\Module\PanelCustomer\UI\Controller\Api\AgentApiController;
use App\Repository\BackupDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BackupTargetCredentialsPayloadTest extends TestCase
{
    #[DataProvider('destinations')]
    public function testEncryptedCredentialEnvelopesReachDownloadsAndAgentJobs(BackupDestinationType $type): void
    {
        $encryption = new EncryptionService('test', 'test:'.base64_encode(str_repeat('k', 32)));
        $customer = new User('backup@example.test', UserType::Customer);
        $target = new BackupTarget($customer, $type, 'Remote', ['url' => 'https://backup.example.test'], [
            'username' => $encryption->encrypt('backup-user'),
            'password' => $encryption->encrypt('sensitive-password'),
            'token' => $encryption->encrypt('remote-token'),
            'ignored' => $encryption->encrypt('do-not-forward'),
        ]);
        $definition = new BackupDefinition($customer, BackupTargetType::Game, '42', null, $target);
        $backup = new Backup($definition, BackupStatus::Succeeded);
        $download = $this->uninitializedController(CustomerInstanceActionApiController::class, $encryption);
        $downloadPayload = (new \ReflectionMethod($download, 'buildBackupDownloadPayload'))->invoke($download, $backup, '/backups/42.tar.gz');

        $agent = $this->uninitializedController(AgentApiController::class, $encryption);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn(new ClassMetadata(BackupDefinition::class));
        $em->method('find')->willReturn($definition);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);
        $repository = new BackupDefinitionRepository($registry);
        (new \ReflectionProperty($agent, 'backupDefinitionRepository'))->setValue($agent, $repository);
        $agentPayload = (new \ReflectionMethod($agent, 'resolveBackupTargetPayload'))->invoke($agent, 'instance.backup.create', ['definition_id' => 42]);

        $expected = ['username' => 'backup-user', 'password' => 'sensitive-password', 'token' => 'remote-token'];
        self::assertEquals($expected, $downloadPayload['backup_target_secret'] ?? null);
        self::assertEquals($expected, $agentPayload['backup_target_secret'] ?? null);
        self::assertSame($type->value, $downloadPayload['backup_target_type']);
        self::assertSame($type->value, $agentPayload['backup_target_type']);
    }

    public static function destinations(): iterable
    {
        yield 'webdav' => [BackupDestinationType::Webdav];
        yield 'nextcloud' => [BackupDestinationType::Nextcloud];
    }

    /** @param class-string $class */
    private function uninitializedController(string $class, EncryptionService $encryption): object
    {
        $controller = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($class, 'encryptionService'))->setValue($controller, $encryption);

        return $controller;
    }
}
