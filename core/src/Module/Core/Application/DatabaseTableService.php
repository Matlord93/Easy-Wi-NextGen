<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Database;
use App\Infrastructure\Config\JsonArray;

/** @psalm-type DatabaseRow = array<array-key, null|bool|int|float|string> */
final class DatabaseTableService implements DatabaseTableServiceInterface
{
    private const int MAX_LIMIT = 50;
    private const int EXPORT_BATCH = 500;
    public const int IMPORT_MAX_BYTES = 10485760;
    private const array ALLOWED_COLUMN_TYPES = ['INT', 'BIGINT', 'VARCHAR', 'TEXT', 'LONGTEXT', 'DECIMAL', 'DATETIME', 'TIMESTAMP', 'DATE', 'TIME', 'BOOLEAN'];
    private const array NON_EDITABLE_TYPES = ['BLOB', 'BINARY', 'VARBINARY', 'LONGBLOB', 'MEDIUMBLOB', 'TINYBLOB', 'GEOMETRY'];
    private const int MAX_EDIT_VALUE_LENGTH = 20000;

    /** @param (\Closure(string, string, string): \PDO)|null $pdoFactory */
    public function __construct(
        private readonly EncryptionService $encryptionService,
        private readonly ?\Closure $pdoFactory = null,
    ) {
    }

    /** @return list<array{name:string,engine:?string,rows:?int}> */
    #[\Override]
    public function listTables(Database $database): array
    {
        $pdo = $this->openPdo($database);
        if ($pdo === null) {
            return [];
        }

        $stmt = self::prepare($pdo, 'SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS rows_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema ORDER BY TABLE_NAME ASC');
        $stmt->execute(['schema' => $database->getName()]);
        $rows = self::fetchRows($stmt);

        return array_map(static fn (array $row): array => [
            'name' => (string) ($row['name'] ?? ''),
            'engine' => isset($row['engine']) ? (string) $row['engine'] : null,
            'rows' => isset($row['rows_count']) ? (int) $row['rows_count'] : null,
        ], $rows);
    }

    /** @return list<array{name:string,type:string,null:string,key:string,default:null|bool|int|float|string,extra:string}> */
    #[\Override]
    public function describeTable(Database $database, string $table): array
    {
        $this->assertValidTableName($table);
        $pdo = $this->openPdo($database);
        if ($pdo === null) {
            return [];
        }

        $stmt = self::prepare($pdo, 'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_KEY AS col_key, COLUMN_DEFAULT AS col_default, EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table ORDER BY ORDINAL_POSITION ASC');
        $stmt->execute(['schema' => $database->getName(), 'table' => $table]);
        $rows = self::fetchRows($stmt);

        return array_map(static fn (array $row): array => [
            'name' => (string) ($row['name'] ?? ''),
            'type' => (string) ($row['type'] ?? ''),
            'null' => (string) ($row['nullable'] ?? ''),
            'key' => (string) ($row['col_key'] ?? ''),
            'default' => $row['col_default'] ?? null,
            'extra' => (string) ($row['extra'] ?? ''),
        ], $rows);
    }

    /** @return array{rows:list<DatabaseRow>,limit:int,offset:int} */
    #[\Override]
    public function listRows(Database $database, string $table, int $limit = 50, int $offset = 0): array
    {
        $this->assertValidTableName($table);
        $pdo = $this->openPdo($database);
        if ($pdo === null) {
            return ['rows' => [], 'limit' => 0, 'offset' => 0];
        }

        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        $quotedTable = sprintf('`%s`', str_replace('`', '``', $table));
        $sql = sprintf('SELECT * FROM %s LIMIT %d OFFSET %d', $quotedTable, $limit, $offset);
        $stmt = $pdo->query($sql);
        $rows = $stmt !== false ? self::fetchRows($stmt) : [];

        $normalized = array_map(/**
         * @param DatabaseRow $row
         * @return DatabaseRow
         */ function (array $row): array {
            foreach ($row as $k => $v) {
                if (is_string($v) && strlen($v) > 200) {
                    $row[$k] = substr($v, 0, 200) . '…';
                }
            }

            return $row;
        }, $rows);

        return ['rows' => $normalized, 'limit' => $limit, 'offset' => $offset];
    }

    private static function pdo(mixed $connection): \PDO
    {
        if (!$connection instanceof \PDO) {
            throw new \UnexpectedValueException('Database connection factory must return PDO.');
        }
        return $connection;
    }

    private static function prepare(\PDO $pdo, string $sql): \PDOStatement
    {
        $statement = $pdo->prepare($sql);
        if ($statement === false) {
            throw new \RuntimeException('database_statement_prepare_failed');
        }
        return $statement;
    }

    /** @return list<DatabaseRow> */
    private static function fetchRows(\PDOStatement $statement): array
    {
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        $normalized = [];
        foreach (array_keys($rows) as $key) {
            $normalized[] = self::databaseRow($rows[$key]);
        }
        return $normalized;
    }

    /** @return DatabaseRow|null */
    private static function optionalRow(mixed $value): ?array
    {
        return is_array($value) ? self::databaseRow($value) : null;
    }

    /** @return DatabaseRow */
    private static function databaseRow(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Invalid database row.');
        }
        $row = [];
        foreach (array_keys($value) as $key) {
            $row[$key] = self::databaseValue($value[$key]);
        }
        return $row;
    }

    private static function databaseValue(mixed $value): null|bool|int|float|string
    {
        if (!is_scalar($value) && $value !== null) {
            throw new \InvalidArgumentException('invalid_database_value');
        }
        return $value;
    }

    private static function stringValue(mixed $value): string
    {
        return (string) self::databaseValue($value);
    }

    private static function quote(\PDO $pdo, string $value): string
    {
        $quoted = $pdo->quote($value);
        if ($quoted === false) {
            throw new \RuntimeException('database_value_quote_failed');
        }
        return $quoted;
    }

    private function assertValidTableName(string $table): void
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $table)) {
            throw new \InvalidArgumentException('invalid_table_name');
        }
    }

    private function assertValidColumnName(string $column): void
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $column)) {
            throw new \InvalidArgumentException('invalid_column_name');
        }
    }

    #[\Override]
    public function createTable(Database $database, string $tableName, array $columns): void
    {
        $this->assertValidTableName($tableName);
        if ($columns === []) {
            throw new \InvalidArgumentException('create_table_columns_required');
        }
        $pdo = $this->openPdo($database);
        if ($pdo === null) {
            throw new \RuntimeException('connection_unavailable');
        }

        $defs = [];
        $pk = [];
        foreach (array_keys($columns) as $index) {
            $column = JsonArray::orEmpty($columns[$index]);
            $name = strtoupper(trim((string) ($column['name'] ?? ''))) === '' ? '' : (string) ($column['name'] ?? '');
            $this->assertValidColumnName($name);
            $type = strtoupper(trim((string) ($column['type'] ?? '')));
            if (!in_array($type, self::ALLOWED_COLUMN_TYPES, true)) {
                throw new \InvalidArgumentException('invalid_column_type');
            }
            $length = trim((string) ($column['length'] ?? ''));
            $nullable = (bool) ($column['nullable'] ?? false);
            $primary = (bool) ($column['primary'] ?? false);
            $autoIncrement = (bool) ($column['auto_increment'] ?? false);
            $defaultValue = self::databaseValue($column['default'] ?? null);

            $sqlType = $type;
            if (in_array($type, ['VARCHAR', 'INT', 'BIGINT'], true) && $length !== '') {
                if (!preg_match('/^\d{1,4}$/', $length)) {
                    throw new \InvalidArgumentException('invalid_column_length');
                }
                $sqlType .= sprintf('(%d)', (int) $length);
            } elseif ($type === 'DECIMAL' && $length !== '') {
                if (!preg_match('/^\d{1,2},\d{1,2}$/', $length)) {
                    throw new \InvalidArgumentException('invalid_column_length');
                }
                $sqlType .= '(' . $length . ')';
            }

            if ($autoIncrement && !in_array($type, ['INT', 'BIGINT'], true)) {
                throw new \InvalidArgumentException('invalid_auto_increment');
            }

            $colDef = sprintf('`%s` %s %s', str_replace('`', '``', $name), $sqlType, $nullable ? 'NULL' : 'NOT NULL');
            if ($defaultValue !== null && $defaultValue !== '') {
                $colDef .= ' DEFAULT ' . self::quote($pdo, self::stringValue($defaultValue));
            }
            if ($autoIncrement) {
                $colDef .= ' AUTO_INCREMENT';
            }
            $defs[] = $colDef;
            if ($primary) {
                $pk[] = sprintf('`%s`', str_replace('`', '``', $name));
            }
        }

        if ($pk !== []) {
            $defs[] = 'PRIMARY KEY (' . implode(', ', $pk) . ')';
        }

        $sql = sprintf('CREATE TABLE `%s` (%s)', str_replace('`', '``', $tableName), implode(', ', $defs));
        $pdo->exec($sql);
    }

    /** @return list<string> */
    #[\Override]
    public function listPrimaryKeyColumns(Database $database, string $table): array
    {
        $this->assertValidTableName($table);
        $columns = $this->describeTable($database, $table);
        $pk = [];
        foreach ($columns as $column) {
            if (($column['key'] ?? '') === 'PRI') {
                $pk[] = ($column['name'] ?? '');
            }
        }
        return $pk;
    }

    /** @return list<array{name:string,type:string,editable:bool,primary:bool}> */
    public function getEditableColumns(Database $database, string $table): array
    {
        $columns = $this->describeTable($database, $table);
        $out = [];
        foreach ($columns as $column) {
            $name = ($column['name'] ?? '');
            $type = strtoupper(($column['type'] ?? ''));
            $baseType = (string) preg_replace('/\(.*/', '', $type);
            $isPrimary = (($column['key'] ?? '') === 'PRI');
            $out[] = [
                'name' => $name,
                'type' => $type,
                'editable' => !$isPrimary && !in_array($baseType, self::NON_EDITABLE_TYPES, true),
                'primary' => $isPrimary,
            ];
        }
        return $out;
    }

    /**
     * @return array{pk:string,row:DatabaseRow,columns:list<array{name:string,type:string,editable:bool,primary:bool}>}
     */
    #[\Override]
    public function getEditableRow(Database $database, string $table, string $pkValue): array
    {
        $this->assertValidTableName($table);
        $pk = $this->listPrimaryKeyColumns($database, $table);
        if ($pk === []) {
            throw new \InvalidArgumentException('edit_requires_primary_key');
        }
        if (count($pk) > 1) {
            throw new \InvalidArgumentException('edit_composite_primary_key_not_supported');
        }
        $this->assertValidColumnName($pk[0]);
        $pdo = $this->openPdo($database);
        if ($pdo === null) {
            throw new \RuntimeException('connection_unavailable');
        }
        $qTable = sprintf('`%s`', str_replace('`', '``', $table));
        $qPk = sprintf('`%s`', str_replace('`', '``', $pk[0]));
        $stmt = self::prepare($pdo, sprintf('SELECT * FROM %s WHERE %s = :pk LIMIT 1', $qTable, $qPk));
        $stmt->execute(['pk' => $pkValue]);
        $rawRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($rawRow)) {
            throw new \InvalidArgumentException('edit_row_not_found');
        }

        return ['pk' => $pk[0], 'row' => self::databaseRow($rawRow), 'columns' => $this->getEditableColumns($database, $table)];
    }

    #[\Override]
    public function updateRow(Database $database, string $table, string $pkValue, array $input): void
    {
        $row = $this->getEditableRow($database, $table, $pkValue);
        $pk = $row['pk'];
        $editable = array_filter($row['columns'], static fn (array $column): bool => ($column['editable'] ?? false) === true);
        $editableNames = array_map(static fn (array $column): string => ($column['name'] ?? ''), $editable);
        foreach (array_keys($input) as $inputColumnName) {
            if (!is_string($inputColumnName)) {
                throw new \InvalidArgumentException('invalid_column_name');
            }
            $this->assertValidColumnName($inputColumnName);
            if ($inputColumnName === $pk) {
                continue;
            }
            if (!in_array($inputColumnName, $editableNames, true)) {
                throw new \InvalidArgumentException('invalid_column_name');
            }
        }
        $sets = [];
        $params = ['pk' => $pkValue];
        foreach ($editable as $column) {
            $name = ($column['name'] ?? '');
            $this->assertValidColumnName($name);
            if (!array_key_exists($name, $input)) {
                continue;
            }
            $value = self::databaseValue($input[$name]);
            if (is_string($value) && strlen($value) > self::MAX_EDIT_VALUE_LENGTH) {
                throw new \InvalidArgumentException('edit_value_too_large');
            }
            $param = 'c_' . $name;
            $sets[] = sprintf('`%s` = :%s', str_replace('`', '``', $name), $param);
            $params[$param] = $value === '__NULL__' ? null : $value;
        }
        if ($sets === []) {
            throw new \InvalidArgumentException('edit_no_editable_fields');
        }
        $pdo = $this->openPdo($database);
        if ($pdo === null) {
            throw new \RuntimeException('connection_unavailable');
        }
        $qTable = sprintf('`%s`', str_replace('`', '``', $table));
        $qPk = sprintf('`%s`', str_replace('`', '``', $pk));
        $stmt = self::prepare($pdo, sprintf('UPDATE %s SET %s WHERE %s = :pk', $qTable, implode(', ', $sets), $qPk));
        $stmt->execute($params);
    }

    private function openPdo(Database $database): ?\PDO
    {
        $engine = strtolower($database->getEngine());
        if (!in_array($engine, ['mysql', 'mariadb'], true)) {
            return null;
        }

        $encrypted = $database->getEncryptedPassword();
        if (!is_array($encrypted)) {
            return null;
        }
        $password = $this->encryptionService->decrypt($encrypted);
        if ($password === '') {
            return null;
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $database->getHost(), $database->getPort(), $database->getName());
        return $this->pdoFactory instanceof \Closure
            ? self::pdo(($this->pdoFactory)($dsn, $database->getUsername(), $password))
            : new \PDO($dsn, $database->getUsername(), $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT => 5,
            ]);
    }

    /** @return list<string> */
    #[\Override]
    public function exportTableSql(Database $database, string $table): array
    {
        $this->assertValidTableName($table);
        $pdo = $this->openPdo($database);
        if ($pdo === null) {
            return [];
        }

        $qTable = sprintf('`%s`', str_replace('`', '``', $table));
        $createStmt = $pdo->query('SHOW CREATE TABLE ' . $qTable);
        $createRow = $createStmt !== false ? self::optionalRow($createStmt->fetch(\PDO::FETCH_ASSOC)) : null;
        $createSql = (string) ($createRow['Create Table'] ?? '');
        $createSql = (string) preg_replace('/\s+DEFINER\s*=\s*`[^`]+`@`[^`]+`/i', '', $createSql);
        if ($createSql === '') {
            return [];
        }

        $lines = [
            sprintf('-- Table export: %s', $table),
            $createSql . ';',
            '',
        ];

        $offset = 0;
        do {
            $stmt = $pdo->query(sprintf('SELECT * FROM %s LIMIT %d OFFSET %d', $qTable, self::EXPORT_BATCH, $offset));
            $rows = $stmt !== false ? self::fetchRows($stmt) : [];
            foreach ($rows as $row) {
                $cols = array_map(static fn (int|string $col): string => sprintf('`%s`', str_replace('`', '``', (string) $col)), array_keys($row));
                $vals = array_map(fn (mixed $v): string => $this->sqlValue($pdo, $v), array_values($row));
                $lines[] = sprintf('INSERT INTO %s (%s) VALUES (%s);', $qTable, implode(', ', $cols), implode(', ', $vals));
            }
            $offset += self::EXPORT_BATCH;
        } while (count($rows) === self::EXPORT_BATCH);

        $lines[] = '';

        return $lines;
    }

    /** @return list<string> */
    #[\Override]
    public function exportDatabaseSql(Database $database): array
    {
        $tables = $this->listTables($database);
        $out = [
            sprintf('-- Export generated at %s', (new \DateTimeImmutable())->format(DATE_ATOM)),
            sprintf('-- Database: %s', $database->getName()),
            '',
        ];
        foreach ($tables as $table) {
            $name = ($table['name'] ?? '');
            if ($name === '') {
                continue;
            }
            foreach ($this->exportTableSql($database, $name) as $line) {
                $out[] = $line;
            }
        }

        return $out;
    }

    private function sqlValue(\PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value) && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
            return '0x' . strtoupper(bin2hex($value));
        }

        return self::quote($pdo, self::stringValue($value));
    }

    #[\Override]
    public function importSql(Database $database, string $filename, string $content): void
    {
        if (!str_ends_with(strtolower($filename), '.sql')) {
            throw new \InvalidArgumentException('import_invalid_extension');
        }
        if (strlen($content) > self::IMPORT_MAX_BYTES) {
            throw new \InvalidArgumentException('import_file_too_large');
        }
        $pdo = $this->openPdo($database);
        if ($pdo === null) {
            throw new \RuntimeException('import_connection_unavailable');
        }

        $fullNormalized = strtolower(preg_replace('/\s+/', ' ', $this->stripInlineComments($content)) ?? '');
        if (str_contains($fullNormalized, 'delimiter ')) {
            throw new \InvalidArgumentException('import_delimiter_not_supported');
        }
        foreach (['create user','drop user','alter user','grant ','revoke ','set password','load data',' into outfile','create database','drop database','alter database','definer=','create trigger','create procedure','create function','create event'] as $blocked) {
            if (str_contains($fullNormalized, $blocked)) {
                throw new \InvalidArgumentException('import_statement_blocked');
            }
        }

        foreach ($this->splitSqlStatements($content) as $sql) {
            if ($sql === '') {
                continue;
            }
            $this->validateImportStatement($database, $sql);
            $pdo->exec($sql);
        }

    }

    /** @return list<string> */
    private function splitSqlStatements(string $sql): array
    {
        $sql = $this->stripInlineComments($sql);
        $out=[];$cur='';$inS=false;$inD=false;$esc=false;
        $len=strlen($sql);
        for($i=0;$i<$len;$i++){
            $ch=$sql[$i];
            if($esc){$cur.=$ch;$esc=false;continue;}
            if($ch==='\\'){ $cur.=$ch; $esc=true; continue; }
            if($ch==="'" && !$inD){$inS=!$inS;$cur.=$ch;continue;}
            if($ch==='"' && !$inS){$inD=!$inD;$cur.=$ch;continue;}
            if($ch===';' && !$inS && !$inD){$out[]=trim($cur);$cur='';continue;}
            $cur.=$ch;
        }
        if(trim($cur)!==''){$out[]=trim($cur);} 
        return $out;
    }

    private function validateImportStatement(Database $database, string $sql): void
    {
        $stripped = trim($this->stripLeadingComments($sql));
        if ($stripped === '') {
            return;
        }
        $n = strtolower(trim(preg_replace('/\s+/', ' ', $stripped) ?? ''));
        if (preg_match('/^\s*delimiter\b/i', $n)) {
            throw new \InvalidArgumentException('import_delimiter_not_supported');
        }
        foreach (['create user','drop user','alter user','grant ','revoke ','set password','load data',' into outfile','create database','drop database','alter database'] as $bad) {
            if (str_contains($n, $bad)) {
                throw new \InvalidArgumentException('import_statement_blocked');
            }
        }
        foreach (['definer=', 'create trigger', 'create procedure', 'create function', 'create event'] as $blocked) {
            if (str_contains($n, $blocked)) {
                throw new \InvalidArgumentException('import_statement_blocked');
            }
        }
        if (preg_match('/^use\s+`?([a-z0-9_]+)`?$/i', rtrim($n, ';'), $m)) {
            if (strtolower($m[1]) !== strtolower($database->getName())) {
                throw new \InvalidArgumentException('import_use_blocked');
            }
            return;
        }
        if (preg_match('/(?:`([a-z0-9_]+)`|([a-z0-9_]+))\s*\.\s*`?[a-z0-9_]+`?/i', $n, $m)) {
            $qualifier = strtolower(($m[1] !== '' ? $m[1] : $m[2]));
            if ($qualifier !== strtolower($database->getName())) {
                throw new \InvalidArgumentException('import_cross_database_blocked');
            }
        }
        if (preg_match('/\b(select|insert|update|delete)\b.*\binto\s+outfile\b/i', $n)) {
            throw new \InvalidArgumentException('import_statement_blocked');
        }
        if (!preg_match('/^(create table|drop table|truncate table|alter table|insert into|update |delete from|create index|drop index|lock tables|unlock tables|set names|set foreign_key_checks|\/\*!)/i', $n)) {
            throw new \InvalidArgumentException('import_statement_not_allowed');
        }
    }

    private function stripLeadingComments(string $sql): string
    {
        $s = ltrim($sql);
        while (true) {
            if (str_starts_with($s, '--')) {
                $pos = strpos($s, "\n");
                $s = $pos === false ? '' : ltrim(substr($s, $pos + 1));
                continue;
            }
            if (str_starts_with($s, '#')) {
                $pos = strpos($s, "\n");
                $s = $pos === false ? '' : ltrim(substr($s, $pos + 1));
                continue;
            }
            if (str_starts_with($s, '/*')) {
                $pos = strpos($s, '*/');
                $s = $pos === false ? '' : ltrim(substr($s, $pos + 2));
                continue;
            }
            break;
        }

        return $s;
    }

    private function stripInlineComments(string $sql): string
    {
        $out = '';
        $len = strlen($sql);
        $inS = false;
        $inD = false;
        $esc = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];

            if ($esc) {
                $out .= $ch;
                $esc = false;
                continue;
            }
            if ($ch === '\\') {
                $out .= $ch;
                $esc = true;
                continue;
            }
            if ($ch === "'" && !$inD) {
                $inS = !$inS;
                $out .= $ch;
                continue;
            }
            if ($ch === '"' && !$inS) {
                $inD = !$inD;
                $out .= $ch;
                continue;
            }
            if (!$inS && !$inD) {
                if ($ch === '#' ) {
                    while ($i < $len && $sql[$i] !== "\n") { $i++; }
                    $out .= "\n";
                    continue;
                }
                if ($ch === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
                    $third = $i + 2 < $len ? $sql[$i + 2] : '';
                    if ($third === ' ' || $third === "\t" || $third === "\n" || $third === "\r") {
                        while ($i < $len && $sql[$i] !== "\n") { $i++; }
                        $out .= "\n";
                        continue;
                    }
                }
                if ($ch === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
                    if ($i + 2 < $len && $sql[$i + 2] === '!') {
                        // MySQL version-conditional comment /*!NNNNN ... */ — preserve inner content
                        $i += 2;
                        while ($i < $len && ($sql[$i] === '!' || ($sql[$i] >= '0' && $sql[$i] <= '9'))) { $i++; }
                        while ($i + 1 < $len && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                            $out .= $sql[$i];
                            $i++;
                        }
                        $i += 1;
                    } else {
                        $i += 2;
                        while ($i + 1 < $len && !($sql[$i] === '*' && $sql[$i + 1] === '/')) { $i++; }
                        $i += 1;
                        $out .= ' ';
                    }
                    continue;
                }
            }

            $out .= $ch;
        }

        return $out;
    }
}
