<?php

declare(strict_types=1);

namespace App\Module\EasyWiMigration\Application;

/**
 * Read-only access to the legacy Easy-Wi 3.x database.
 * Opens a dedicated PDO connection so it never touches the current DB.
 */
final class EasyWiSourceReader
{
    private \PDO $pdo;
    private string $prefix;

    public function __construct(EasyWiConnectionConfig $config)
    {
        $this->pdo = new \PDO(
            $config->dsn(),
            $config->username,
            $config->password,
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4'",
            ],
        );
        $this->prefix = $config->tablePrefix;
    }

    /** @return list<array<string, scalar|null>> */
    public function fetchUsers(int $offset = 0, int $limit = 500): array
    {
        return $this->query(
            "SELECT id, email, password, firstname, lastname, company, street, zip, city, country,
                    phone, created, last_login, language, reseller_id, type
             FROM {$this->prefix}users
             ORDER BY id ASC
             LIMIT :limit OFFSET :offset",
            ['limit' => $limit, 'offset' => $offset],
        );
    }

    public function countTable(string $table): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM {$this->prefix}{$table}");
        return (int) $stmt->fetchColumn();
    }

    public function tableExists(string $table): bool
    {
        try {
            $this->pdo->query("SELECT 1 FROM {$this->prefix}{$table} LIMIT 1");
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * @param array<string, int|string> $params
     * @return list<array<string, scalar|null>>
     */
    private function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(
                ':' . $key,
                $value,
                is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR,
            );
        }
        $stmt->execute();
        $fetched = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $rows = array_filter($fetched, 'is_array');
        if (count($rows) !== count($fetched)) {
            throw new \UnexpectedValueException('Legacy database returned an invalid row.');
        }
        $result = [];
        foreach ($rows as $row) {
            $record = [];
            foreach (array_keys($row) as $key) {
                if (!is_string($key)) {
                    throw new \UnexpectedValueException('Legacy database returned an invalid column name.');
                }
                $record[$key] = self::scalar($row[$key]);
            }
            $result[] = $record;
        }

        return $result;
    }
    private static function scalar(mixed $value): string|int|float|bool|null
    {
        if (!is_scalar($value) && $value !== null) {
            throw new \UnexpectedValueException('Legacy database returned an unsupported column.');
        }

        return $value;
    }

}
