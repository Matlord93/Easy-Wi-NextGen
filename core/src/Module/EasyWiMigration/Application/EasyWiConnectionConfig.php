<?php

declare(strict_types=1);

namespace App\Module\EasyWiMigration\Application;

/**
 * Immutable connection parameters for the source Easy-Wi 3.x database.
 */
final class EasyWiConnectionConfig
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $dbName,
        public readonly string $username,
        public readonly string $password,
        public readonly string $tablePrefix = 'easywi_',
    ) {
    }

    public function dsn(): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->host,
            $this->port,
            $this->dbName,
        );
    }
}
