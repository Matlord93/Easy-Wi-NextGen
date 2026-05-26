<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application;

use App\Module\Core\Application\DatabaseTableService;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Database;
use App\Module\Core\Domain\Entity\DatabaseNode;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use PHPUnit\Framework\TestCase;

final class DatabaseTableServiceTest extends TestCase
{
    public function testListTablesStillWorksAfterOneTimeRevealConsumptionBecauseEncryptedPasswordRemains(): void
    {
        $encryption = $this->createMock(EncryptionService::class);
        $encryption->method('decrypt')->willReturn('operational-password');

        $statement = new class {
            public array $params = [];
            public function execute(array $params): void { $this->params = $params; }
            public function fetchAll(): array {
                return [['name' => 'users', 'engine' => 'InnoDB', 'rows_count' => 2]];
            }
        };

        $pdo = new class($statement) {
            public function __construct(private object $statement) {}
            public function prepare(string $sql): object { return $this->statement; }
        };

        $service = new DatabaseTableService($encryption, static fn(string $dsn, string $user, string $pass) => $pdo);

        $customer = new User('customer@example.test', UserType::Customer);
        $agent = new Agent('agent-db-1', ['key_id'=>'k','nonce'=>'n','ciphertext'=>'c'], 'DB Agent');
        $node = new DatabaseNode('db-node-1', 'mariadb', '127.0.0.1', 3306, $agent);

        $database = new Database($customer, 'mariadb', '127.0.0.1', 3306, 'u2_demo', 'u2_demo', ['key_id'=>'k','nonce'=>'n','ciphertext'=>'c'], $node);
        $database->setEncryptedOneTimeCredential(['key_id'=>'k2','nonce'=>'n2','ciphertext'=>'c2']);

        // simulate reveal consumption lifecycle: one-time secret consumed, operational password remains.
        $database->setEncryptedOneTimeCredential(null);

        $tables = $service->listTables($database);

        self::assertCount(1, $tables);
        self::assertSame('users', $tables[0]['name']);
        self::assertSame('InnoDB', $tables[0]['engine']);
        self::assertSame(2, $tables[0]['rows']);
        self::assertNotNull($database->getEncryptedPassword());
        self::assertNull($database->getEncryptedOneTimeCredential());
    }
}
