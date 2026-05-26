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

final class DatabaseExportServiceTest extends TestCase
{
    public function testTableExportContainsCreateAndInsertAndEscapesStrings(): void
    {
        $enc = $this->createMock(EncryptionService::class);
        $enc->method('decrypt')->willReturn('pw');

        $pdo = new class {
            public array $queries = [];
            public function prepare(string $sql): object {
                if (str_contains($sql, 'information_schema.TABLES')) {
                    return new class { public function execute(array $p): void {} public function fetchAll(): array { return [['name'=>'users','engine'=>'InnoDB','rows_count'=>1]]; } };
                }
                return new class { public function execute(array $p): void {} public function fetchAll(): array { return []; } };
            }
            public function query(string $sql): object|false {
                $this->queries[] = $sql;
                if (str_starts_with($sql, 'SHOW CREATE TABLE')) {
                    return new class { public function fetch(int $mode=0): array { return ['Create Table' => 'CREATE DEFINER=`root`@`localhost` TABLE `users` (`id` int, `name` varchar(255))']; } };
                }
                return new class { public function fetchAll(int $mode=0): array { return [['id'=>1,'name'=>"O'Reilly"]]; } };
            }
            public function quote(string $v): string { return "'" . str_replace("'", "\\'", $v) . "'"; }
        };

        $service = new DatabaseTableService($enc, static fn() => $pdo);
        $db = $this->db();
        $lines = $service->exportTableSql($db, 'users');
        $sql = implode("\n", $lines);
        self::assertStringContainsString('TABLE `users`', $sql);
        self::assertStringNotContainsString('DEFINER', strtoupper($sql));
        self::assertStringContainsString('INSERT INTO `users`', $sql);
        self::assertStringContainsString("'O\\'Reilly'", $sql);
        self::assertStringNotContainsString('admin_secret', $sql);
    }

    private function db(): Database
    {
        $u = new User('u@test', UserType::Customer);
        $a = new Agent('a', ['key_id'=>'k','nonce'=>'n','ciphertext'=>'c'], 'A');
        $n = new DatabaseNode('n', 'mariadb', '127.0.0.1', 3306, $a);
        return new Database($u, 'mariadb', '127.0.0.1', 3306, 'u2_demo', 'u2_demo', ['key_id'=>'k','nonce'=>'n','ciphertext'=>'c'], $n);
    }
}
