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

final class DatabaseTableReadOnlyTest extends TestCase
{
    public function testInvalidTableNameRejected(): void
    {
        $service = new DatabaseTableService($this->createMock(EncryptionService::class), static fn() => null);
        $db = $this->newDatabase();
        $this->expectException(\InvalidArgumentException::class);
        $service->describeTable($db, 'users;DROP');
    }

    public function testRowsPaginationLimitEnforcedAndUsesCustomerUser(): void
    {
        $encryption = $this->createMock(EncryptionService::class);
        $encryption->method('decrypt')->willReturn('customer-pass');

        $stmt = new class {
            public function fetchAll(): array { return [['id'=>1,'content'=>str_repeat('x',300)]]; }
        };

        $captured = ['dsn'=>'','user'=>'','pass'=>'','query'=>''];
        $pdo = new class($stmt, $captured) {
            public array $capt;
            public function __construct(private object $stmt, array $capt){ $this->capt=$capt; }
            public function query(string $sql): object { $this->capt['query']=$sql; return $this->stmt; }
            public function prepare(string $sql): object { return new class { public function execute(array $p): void {} public function fetchAll(): array { return []; } }; }
        };

        $factory = function (string $dsn, string $user, string $pass) use ($pdo, &$captured) {
            $captured = ['dsn'=>$dsn,'user'=>$user,'pass'=>$pass];
            return $pdo;
        };

        $service = new DatabaseTableService($encryption, $factory);
        $db = $this->newDatabase();
        $out = $service->listRows($db, 'users', 500, 0);

        self::assertSame('u2_demo', $captured['user']);
        self::assertSame('customer-pass', $captured['pass']);
        self::assertSame(50, $out['limit']);
        self::assertStringContainsString('LIMIT 50 OFFSET 0', $pdo->capt['query']);
        self::assertStringEndsWith('…', $out['rows'][0]['content']);
    }

    private function newDatabase(): Database
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $agent = new Agent('agent-db-1', ['key_id'=>'k','nonce'=>'n','ciphertext'=>'c'], 'DB Agent');
        $node = new DatabaseNode('db-node-1', 'mariadb', '127.0.0.1', 3306, $agent);
        return new Database($customer, 'mariadb', '127.0.0.1', 3306, 'u2_demo', 'u2_demo', ['key_id'=>'k','nonce'=>'n','ciphertext'=>'c'], $node);
    }
}
