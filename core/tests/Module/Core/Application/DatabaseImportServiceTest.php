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

final class DatabaseImportServiceTest extends TestCase
{
    public function testImportBlocksDangerousStatementsAndAllowsCreateInsert(): void
    {
        $enc = $this->createMock(EncryptionService::class);
        $enc->method('decrypt')->willReturn('pw');

        $pdo = new class {
            public array $executed = [];
            public function exec(string $sql): void { $this->executed[] = $sql; }
            public function prepare(string $sql): object { return new class { public function execute(array $p): void {} public function fetchAll(): array { return []; } }; }
        };

        $service = new DatabaseTableService($enc, static fn() => $pdo);
        $db = $this->db();

        $ok = "-- cmt\nCREATE TABLE `t` (`id` int, `txt` varchar(255)); INSERT INTO `t` (`id`,`txt`) VALUES (1,'a;b');";
        $res = $service->importSql($db, 'dump.sql', $ok);
        self::assertSame(2, $res['executed']);

        $this->expectException(\InvalidArgumentException::class);
        $service->importSql($db, 'dump.sql', '/*x*/ GrAnT ALL ON *.* TO x;');
    }

    public function testImportBlocksInvalidExtensionAndCrossDbUse(): void
    {
        $enc = $this->createMock(EncryptionService::class);
        $enc->method('decrypt')->willReturn('pw');
        $pdo = new class { public function exec(string $sql): void {} public function prepare(string $sql): object { return new class { public function execute(array $p): void {} public function fetchAll(): array { return []; } }; } };
        $service = new DatabaseTableService($enc, static fn() => $pdo);
        $db = $this->db();

        try { $service->importSql($db, 'dump.txt', 'CREATE TABLE t (id int);'); self::fail(); } catch (\InvalidArgumentException $e) { self::assertSame('import_invalid_extension', $e->getMessage()); }
        try { $service->importSql($db, 'dump.sql', 'USE other_db;'); self::fail(); } catch (\InvalidArgumentException $e) { self::assertSame('import_use_blocked', $e->getMessage()); }
    }

    public function testImportBlocksBacktickQualifierDelimiterAndProgrammableObjects(): void
    {
        $enc = $this->createMock(EncryptionService::class);
        $enc->method('decrypt')->willReturn('pw');
        $pdo = new class { public function exec(string $sql): void {} public function prepare(string $sql): object { return new class { public function execute(array $p): void {} public function fetchAll(): array { return []; } }; } };
        $service = new DatabaseTableService($enc, static fn() => $pdo);
        $db = $this->db();

        foreach ([
            'INSERT INTO `other_db`.`t` VALUES (1);' => 'import_cross_database_blocked',
            "DELIMITER //\nCREATE TRIGGER t BEFORE INSERT ON x FOR EACH ROW SET @a=1;//" => 'import_delimiter_not_supported',
            'CREATE DEFINER=`root`@`localhost` VIEW v AS SELECT 1;' => 'import_statement_blocked',
            'CREATE FUNCTION f() RETURNS INT RETURN 1;' => 'import_statement_blocked',
            "SELECT 1; /* inline */ GrAnT ALL ON *.* TO x;" => 'import_statement_blocked',
            "/*!50003 CREATE*/ /*!50003 GRANT ALL ON *.* TO x */;" => 'import_statement_blocked',
        ] as $sql => $code) {
            try {
                $service->importSql($db, 'dump.sql', $sql);
                self::fail('expected '.$code);
            } catch (\InvalidArgumentException $e) {
                self::assertSame($code, $e->getMessage());
            }
        }
    }

    private function db(): Database
    {
        $u = new User('u@test', UserType::Customer);
        $a = new Agent('a', ['key_id'=>'k','nonce'=>'n','ciphertext'=>'c'], 'A');
        $n = new DatabaseNode('n', 'mariadb', '127.0.0.1', 3306, $a);
        return new Database($u, 'mariadb', '127.0.0.1', 3306, 'u2_demo', 'u2_demo', ['key_id'=>'k','nonce'=>'n','ciphertext'=>'c'], $n);
    }
}
