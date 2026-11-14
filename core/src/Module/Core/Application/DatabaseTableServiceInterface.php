<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Database;

/** @psalm-import-type DatabaseRow from DatabaseTableService */
interface DatabaseTableServiceInterface
{
    /** @return list<array{name:string,engine:?string,rows:?int}> */
    public function listTables(Database $database): array;

    /** @return list<array{name:string,type:string,null:string,key:string,default:null|bool|int|float|string,extra:string}> */
    public function describeTable(Database $database, string $table): array;

    /** @return array{rows:list<DatabaseRow>,limit:int,offset:int} */
    public function listRows(Database $database, string $table, int $limit = 50, int $offset = 0): array;

    public function createTable(Database $database, string $tableName, array $columns): void;
    /** @return list<string> */
    public function listPrimaryKeyColumns(Database $database, string $table): array;

    /**
     * @return array{pk:string,row:DatabaseRow,columns:list<array{name:string,type:string,editable:bool,primary:bool}>}
     */
    public function getEditableRow(Database $database, string $table, string $pkValue): array;

    public function updateRow(Database $database, string $table, string $pkValue, array $input): void;
    /** @return list<string> */
    public function exportTableSql(Database $database, string $table): array;

    /** @return list<string> */
    public function exportDatabaseSql(Database $database): array;

    public function importSql(Database $database, string $filename, string $content): void;
}
