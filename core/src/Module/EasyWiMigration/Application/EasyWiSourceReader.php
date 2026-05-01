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

    /** @return list<array<string,mixed>> */
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

    /** @return list<array<string,mixed>> */
    public function fetchGameservers(int $offset = 0, int $limit = 500): array
    {
        return $this->query(
            "SELECT id, user_id, name, ip, port, slots, game, status, created
             FROM {$this->prefix}gameserver
             ORDER BY id ASC
             LIMIT :limit OFFSET :offset",
            ['limit' => $limit, 'offset' => $offset],
        );
    }

    /** @return list<array<string,mixed>> */
    public function fetchVoiceServers(int $offset = 0, int $limit = 500): array
    {
        return $this->query(
            "SELECT id, user_id, name, ip, port, slots, type, status, created
             FROM {$this->prefix}voice
             ORDER BY id ASC
             LIMIT :limit OFFSET :offset",
            ['limit' => $limit, 'offset' => $offset],
        );
    }

    /** @return list<array<string,mixed>> */
    public function fetchWebspaces(int $offset = 0, int $limit = 500): array
    {
        return $this->query(
            "SELECT id, user_id, domain, path, status, created
             FROM {$this->prefix}webspace
             ORDER BY id ASC
             LIMIT :limit OFFSET :offset",
            ['limit' => $limit, 'offset' => $offset],
        );
    }

    /** @return list<array<string,mixed>> */
    public function fetchDomains(int $offset = 0, int $limit = 500): array
    {
        return $this->query(
            "SELECT id, user_id, webspace_id, name, status, created
             FROM {$this->prefix}domains
             ORDER BY id ASC
             LIMIT :limit OFFSET :offset",
            ['limit' => $limit, 'offset' => $offset],
        );
    }

    /** @return list<array<string,mixed>> */
    public function fetchMailboxes(int $offset = 0, int $limit = 500): array
    {
        return $this->query(
            "SELECT id, user_id, address, password, quota, status, created
             FROM {$this->prefix}mailboxes
             ORDER BY id ASC
             LIMIT :limit OFFSET :offset",
            ['limit' => $limit, 'offset' => $offset],
        );
    }

    /** @return list<array<string,mixed>> */
    public function fetchInvoices(int $offset = 0, int $limit = 500): array
    {
        return $this->query(
            "SELECT id, user_id, number, amount, currency, status, created, paid_at
             FROM {$this->prefix}invoices
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
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
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
        return $stmt->fetchAll() ?: [];
    }
}
