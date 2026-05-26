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

final class DatabaseTableEditServiceTest extends TestCase
{
    public function testUpdateRowBlocksWithoutPrimaryKey(): void
    {
        $service = $this->serviceForColumnsAndRow([
            ['name' => 'name', 'type' => 'varchar(255)', 'nullable' => 'YES', 'col_key' => '', 'col_default' => null, 'extra' => ''],
        ], ['name' => 'old']);

        $this->expectExceptionObject(new \InvalidArgumentException('edit_requires_primary_key'));
        $service->updateRow($this->db(), 'users', '1', ['name' => 'new']);
    }

    public function testUpdateRowBlocksCompositePrimaryKey(): void
    {
        $service = $this->serviceForColumnsAndRow([
            ['name' => 'id_a', 'type' => 'int', 'nullable' => 'NO', 'col_key' => 'PRI', 'col_default' => null, 'extra' => ''],
            ['name' => 'id_b', 'type' => 'int', 'nullable' => 'NO', 'col_key' => 'PRI', 'col_default' => null, 'extra' => ''],
            ['name' => 'name', 'type' => 'varchar(255)', 'nullable' => 'YES', 'col_key' => '', 'col_default' => null, 'extra' => ''],
        ], ['id_a' => '1', 'id_b' => '2', 'name' => 'old']);

        $this->expectExceptionObject(new \InvalidArgumentException('edit_composite_primary_key_not_supported'));
        $service->updateRow($this->db(), 'users', '1', ['name' => 'new']);
    }

    public function testUpdateRowValidatesTableName(): void
    {
        $service = $this->serviceForColumnsAndRow([], []);
        $this->expectExceptionObject(new \InvalidArgumentException('invalid_table_name'));
        $service->updateRow($this->db(), 'bad name', '1', []);
    }

    public function testUpdateRowValidatesColumnNames(): void
    {
        $service = $this->serviceForColumnsAndRow([
            ['name' => 'id', 'type' => 'int', 'nullable' => 'NO', 'col_key' => 'PRI', 'col_default' => null, 'extra' => ''],
            ['name' => 'name', 'type' => 'varchar(255)', 'nullable' => 'YES', 'col_key' => '', 'col_default' => null, 'extra' => ''],
        ], ['id' => '1', 'name' => 'old']);

        $this->expectExceptionObject(new \InvalidArgumentException('invalid_column_name'));
        $service->updateRow($this->db(), 'users', '1', ['name;drop' => 'x']);
    }

    public function testUpdateRowDoesNotUpdatePrimaryKeyAndUsesPreparedStatement(): void
    {
        $capture = new SqlCapture();
        $service = $this->serviceForColumnsAndRow([
            ['name' => 'id', 'type' => 'int', 'nullable' => 'NO', 'col_key' => 'PRI', 'col_default' => null, 'extra' => ''],
            ['name' => 'name', 'type' => 'varchar(255)', 'nullable' => 'YES', 'col_key' => '', 'col_default' => null, 'extra' => ''],
        ], ['id' => '1', 'name' => 'old'], $capture);

        $service->updateRow($this->db(), 'users', '1', ['name' => "new'; DROP TABLE x;--", 'id' => '999']);

        self::assertStringContainsString('UPDATE `users` SET `name` = :c_name WHERE `id` = :pk', $capture->updateSql);
        self::assertSame('1', $capture->updateParams['pk'] ?? null);
        self::assertSame("new'; DROP TABLE x;--", $capture->updateParams['c_name'] ?? null);
        self::assertStringNotContainsString("new'; DROP TABLE x;--", $capture->updateSql);
        self::assertArrayNotHasKey('c_id', $capture->updateParams);
    }

    public function testUpdateRowBlocksBlobBinaryGeometryFieldsAndTooLargeValues(): void
    {
        $serviceBlob = $this->serviceForColumnsAndRow([
            ['name' => 'id', 'type' => 'int', 'nullable' => 'NO', 'col_key' => 'PRI', 'col_default' => null, 'extra' => ''],
            ['name' => 'bin_data', 'type' => 'blob', 'nullable' => 'YES', 'col_key' => '', 'col_default' => null, 'extra' => ''],
        ], ['id' => '1', 'bin_data' => null]);

        $this->expectExceptionObject(new \InvalidArgumentException('invalid_column_name'));
        $serviceBlob->updateRow($this->db(), 'users', '1', ['bin_data' => 'abc']);
    }

    public function testUpdateRowBlocksTooLargeValues(): void
    {
        $service = $this->serviceForColumnsAndRow([
            ['name' => 'id', 'type' => 'int', 'nullable' => 'NO', 'col_key' => 'PRI', 'col_default' => null, 'extra' => ''],
            ['name' => 'name', 'type' => 'varchar(255)', 'nullable' => 'YES', 'col_key' => '', 'col_default' => null, 'extra' => ''],
        ], ['id' => '1', 'name' => 'old']);

        $this->expectExceptionObject(new \InvalidArgumentException('edit_value_too_large'));
        $service->updateRow($this->db(), 'users', '1', ['name' => str_repeat('a', 20001)]);
    }

    public function testGetEditableRowUsesPrimaryKeyWhereAndMissingRowGivesError(): void
    {
        $capture = new SqlCapture();
        $service = $this->serviceForColumnsAndRow([
            ['name' => 'id', 'type' => 'int', 'nullable' => 'NO', 'col_key' => 'PRI', 'col_default' => null, 'extra' => ''],
            ['name' => 'name', 'type' => 'varchar(255)', 'nullable' => 'YES', 'col_key' => '', 'col_default' => null, 'extra' => ''],
        ], ['id' => '7', 'name' => 'old'], $capture);
        $service->getEditableRow($this->db(), 'users', '7');
        self::assertStringContainsString('WHERE `id` = :pk', $capture->selectSql);
        self::assertSame('7', $capture->selectParams['pk'] ?? null);

        $serviceMissing = $this->serviceForColumnsAndRow([
            ['name' => 'id', 'type' => 'int', 'nullable' => 'NO', 'col_key' => 'PRI', 'col_default' => null, 'extra' => ''],
        ], null);
        $this->expectExceptionObject(new \InvalidArgumentException('edit_row_not_found'));
        $serviceMissing->getEditableRow($this->db(), 'users', '404');
    }

    private function serviceForColumnsAndRow(array $columns, ?array $row, ?SqlCapture $capture = null): DatabaseTableService
    {
        $capture ??= new SqlCapture();

        $pdo = new class($columns, $row, $capture) {
            public function __construct(private array $columns, private ?array $row, private SqlCapture $capture) {}
            public function prepare(string $sql): object
            {
                if (str_contains($sql, 'FROM information_schema.COLUMNS')) {
                    return new class($this->columns) {
                        public function __construct(private array $columns) {}
                        public function execute(array $params): void {}
                        public function fetchAll(): array { return $this->columns; }
                    };
                }
                if (str_starts_with($sql, 'SELECT *')) {
                    $this->capture->selectSql = $sql;
                    return new class($this->row, $this->capture) {
                        public function __construct(private ?array $row, private SqlCapture $capture) {}
                        public function execute(array $params): void { $this->capture->selectParams = $params; }
                        public function fetch(int $mode): array|false { return $this->row ?? false; }
                    };
                }

                $this->capture->updateSql = $sql;
                return new class($this->capture) {
                    public function __construct(private SqlCapture $capture) {}
                    public function execute(array $params): void { $this->capture->updateParams = $params; }
                };
            }
        };

        return new DatabaseTableService($this->enc(), static fn () => $pdo);
    }

    private function enc(): EncryptionService
    {
        $enc = $this->createMock(EncryptionService::class);
        $enc->method('decrypt')->willReturn('pw');
        return $enc;
    }

    private function db(): Database
    {
        $customer = new User('x@test', UserType::Customer);
        $agent = new Agent('a', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c'], 'A');
        $node = new DatabaseNode('n', 'mariadb', '127.0.0.1', 3306, $agent);

        return new Database($customer, 'mariadb', '127.0.0.1', 3306, 'u1_demo', 'u1_demo', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c'], $node);
    }
}

final class SqlCapture
{
    public string $selectSql = '';
    public array $selectParams = [];
    public string $updateSql = '';
    public array $updateParams = [];
}
