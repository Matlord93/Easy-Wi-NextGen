<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Module\Core\Application\DatabaseNamingPolicy;
use App\Module\Core\Application\DatabaseNodeInspector;
use App\Module\Core\Application\DatabaseProvisioningService;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Command\DatabaseSyncUsersCommand;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Database;
use App\Module\Core\Domain\Entity\DatabaseNode;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\DatabaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DatabaseSyncUsersCommandTest extends TestCase
{
    public function testSyncUsersDetectsMissingNodeObjectsAndPrintsCountersWithoutSecrets(): void
    {
        $db = $this->newDatabase();
        $db->setUsername('');
        $db->setEncryptedPassword(null);

        $repo = $this->createMock(DatabaseRepository::class);
        $repo->method('findBy')->willReturn([$db]);

        $provisioning = $this->createMock(DatabaseProvisioningService::class);
        $provisioning->method('buildCreateJobs')->willReturn([new Job('database.create', ['database_id' => '1'])]);

        $inspector = $this->createMock(DatabaseNodeInspector::class);
        $inspector->method('inspect')->willReturn([
            'database_exists' => false,
            'user_exists' => false,
            'grants_ok' => false,
        ]);

        $encryption = $this->createMock(EncryptionService::class);
        $encryption->method('encrypt')->willReturn(['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c']);

        $naming = $this->createMock(DatabaseNamingPolicy::class);
        $naming->method('buildCustomerScopedName')->willReturn('u2_demo');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::atLeastOnce())->method('persist');
        $em->expects(self::once())->method('flush');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $params = []): string {
            return $id . ' ' . json_encode($params);
        });

        $command = new DatabaseSyncUsersCommand($repo, $provisioning, $naming, $inspector, $encryption, $em, $translator);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('database_sync_success', $display);
        self::assertStringContainsString('database_sync_stats', $display);
        self::assertStringContainsString('database_sync_stats_extended', $display);
        self::assertStringContainsString('"%missing_user%":"1"', $display);
        self::assertStringNotContainsString('temporary-secret', $display);
        self::assertStringNotContainsString('password', strtolower($display));
    }

    private function newDatabase(): Database
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $agent = new Agent('agent-db-1', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c'], 'DB Agent');
        $node = new DatabaseNode('db-node-1', 'mariadb', '127.0.0.1', 3306, $agent);

        return new Database($customer, 'mariadb', '127.0.0.1', 3306, 'u2_demo', 'u2_demo', null, $node);
    }
}
