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

final class DatabaseTableWriteServiceTest extends TestCase
{
    public function testCreateTableRejectsInvalidNamesAndTypes(): void
    {
        $service = new DatabaseTableService($this->mockEncryption(), fn() => new class extends \PDO { public function __construct() {} public function quote(string $v, int $type = \PDO::PARAM_STR): string { return "'".$v."'"; } public function exec(string $sql): int|false { return 0; } });
        $db = $this->newDatabase();

        $this->expectException(\InvalidArgumentException::class);
        $service->createTable($db, 'bad name', [['name' => 'id', 'type' => 'INT']]);
    }

    public function testCreateTableUsesControlledSqlWithoutFreeUserStatement(): void
    {
        $pdo = new class extends \PDO { public function __construct() {}
            public string $captured = '';
            public function quote(string $v, int $type = \PDO::PARAM_STR): string { return "'".$v."'"; }
            public function exec(string $sql): int|false { $this->captured = $sql;  return 0; }
        };
        $service = new DatabaseTableService($this->mockEncryption(), fn() => $pdo);

        $service->createTable($this->newDatabase(), 'users', [[
            'name' => 'id', 'type' => 'INT', 'length' => '11', 'nullable' => false, 'primary' => true, 'auto_increment' => true,
        ]]);

        self::assertStringStartsWith('CREATE TABLE `users`', $pdo->captured);
        self::assertStringNotContainsString('; DROP TABLE', $pdo->captured);
    }

    private function mockEncryption(): EncryptionService
    {
        $enc = $this->createStub(EncryptionService::class);
        $enc->method('decrypt')->willReturn('pw');
        return $enc;
    }

    private function newDatabase(): Database
    {
        $customer = new User('x@test', UserType::Customer);
        $agent = new Agent('a', ['key_id'=>'k','nonce'=>'n','ciphertext'=>'c'], 'A');
        $node = new DatabaseNode('n', 'mariadb', '127.0.0.1', 3306, $agent);
        return new Database($customer, 'mariadb', '127.0.0.1', 3306, 'u1_demo', 'u1_demo', ['key_id'=>'k','nonce'=>'n','ciphertext'=>'c'], $node);
    }
}
