<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260527140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fix port_pools.node_id column type to VARCHAR(64) and reconcile FK constraint name.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('port_pools')) {
            return;
        }

        // Drop any incorrectly-named FK constraint on node_id so we can re-add it
        // with the name Doctrine's schema tool expects (FK_44CBF4C6460D9FD7).
        foreach (['FK_PORT_POOLS_NODE', 'FK_44CBF4C6460D9FD7'] as $fk) {
            if ($this->foreignKeyExists('port_pools', $fk)) {
                $this->addSql(sprintf('ALTER TABLE port_pools DROP FOREIGN KEY %s', $fk));
            }
        }

        // Ensure the column is the correct type.  A previous partial schema:update
        // run may have created it as INT instead of VARCHAR(64).
        $this->addSql(
            'ALTER TABLE port_pools MODIFY node_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL'
        );

        // Re-create the index if needed and add the canonical FK.
        if (!$this->indexExists('port_pools', 'IDX_44CBF4C6460D9FD7')) {
            $this->addSql('CREATE INDEX IDX_44CBF4C6460D9FD7 ON port_pools (node_id)');
        }

        $this->addSql(
            'ALTER TABLE port_pools ADD CONSTRAINT FK_44CBF4C6460D9FD7 FOREIGN KEY (node_id) REFERENCES agents (id)'
        );
    }

    public function down(Schema $schema): void
    {
        if (!$this->tableExists('port_pools')) {
            return;
        }

        if ($this->foreignKeyExists('port_pools', 'FK_44CBF4C6460D9FD7')) {
            $this->addSql('ALTER TABLE port_pools DROP FOREIGN KEY FK_44CBF4C6460D9FD7');
        }

        if (!$this->foreignKeyExists('port_pools', 'FK_PORT_POOLS_NODE')) {
            $this->addSql(
                'ALTER TABLE port_pools ADD CONSTRAINT FK_PORT_POOLS_NODE FOREIGN KEY (node_id) REFERENCES agents (id)'
            );
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            return $this->connection->createSchemaManager()->tablesExist([$table]);
        } catch (\Throwable) {
            return false;
        }
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        try {
            $fks = $this->connection->createSchemaManager()->listTableForeignKeys($table);
            foreach ($fks as $fk) {
                if (strtolower($fk->getName()) === strtolower($constraintName)) {
                    return true;
                }
            }
        } catch (\Throwable) {
        }
        return false;
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            $indexes = $this->connection->createSchemaManager()->listTableIndexes($table);
            return array_key_exists(strtolower($indexName), array_change_key_case($indexes, CASE_LOWER));
        } catch (\Throwable) {
            return false;
        }
    }
}
