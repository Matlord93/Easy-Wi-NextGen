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
use App\Module\PanelCustomer\UI\Controller\Customer\CustomerDatabaseController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DatabaseExportServiceTest extends TestCase
{
    public function testSqlDownloadPreservesQuotesAndMarkupAsSqlBytes(): void
    {
        $sql = <<<'SQL'
INSERT INTO `test` VALUES ('<script> & "quoted"');
SQL;
        $chunks = (new \ReflectionMethod(CustomerDatabaseController::class, 'sqlChunks'))->invoke(null, [$sql, '']);
        $response = new StreamedResponse($chunks, headers: ['Content-Type' => 'application/sql; charset=UTF-8']);
        ob_start();
        ob_start();
        $response->sendContent();
        ob_end_clean();
        $content = (string) ob_get_clean();

        self::assertSame($sql."\n\n", $content);
        self::assertSame('application/sql; charset=UTF-8', $response->headers->get('Content-Type'));
    }

    public function testTableExportContainsCreateAndInsertAndEscapesStrings(): void
    {
        $enc = $this->createStub(EncryptionService::class);
        $enc->method('decrypt')->willReturn('pw');

        $pdo = new class extends \PDO { public function __construct() {}
            public array $queries = [];
            public function prepare(string $sql, array $options = []): \PDOStatement|false {
                if (str_contains($sql, 'information_schema.TABLES')) {
                    return new class extends \PDOStatement { public function __construct() {} public function execute(?array $p = null): bool { $p ??= [];  return true; } public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array { return [['name'=>'users','engine'=>'InnoDB','rows_count'=>1]]; } };
                }
                return new class extends \PDOStatement { public function __construct() {} public function execute(?array $p = null): bool { $p ??= [];  return true; } public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array { return []; } };
            }
            public function query(string $sql, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false {
                $this->queries[] = $sql;
                if (str_starts_with($sql, 'SHOW CREATE TABLE')) {
                    return new class extends \PDOStatement { public function __construct() {} public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): array { return ['Create Table' => 'CREATE DEFINER=`root`@`localhost` TABLE `users` (`id` int, `name` varchar(255))']; } };
                }
                return new class extends \PDOStatement { public function __construct() {} public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array { return [['id'=>1,'name'=>"O'Reilly"]]; } };
            }
            public function quote(string $v, int $type = \PDO::PARAM_STR): string { return "'" . str_replace("'", "\\'", $v) . "'"; }
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
