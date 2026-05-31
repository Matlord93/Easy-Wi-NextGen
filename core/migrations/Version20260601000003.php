<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fixes remaining schema drift after Version20260601000002:
 *
 *  • Resizes every node_id / agent_id FK column to VARCHAR(64) to match agents.id.
 *  • Re-adds the corresponding FK constraint with the correct ON DELETE rule.
 *
 * Note: agents.last_heartbeat_ipv6 is still mapped in the Agent entity and must NOT
 * be dropped here.  Version20260601000004 re-adds the column on systems where an
 * earlier version of this migration mistakenly removed it.
 *
 * Why no explicit CHARACTER SET / COLLATE in the CHANGE statements
 * ─────────────────────────────────────────────────────────────────
 * doctrine.yaml uses "collate:" (legacy key) instead of "collation:" in
 * default_table_options.  MySQLSchemaManager.createSchemaConfig() passes
 * that option through as-is, but MySQL\Comparator::normalizeTable() reads
 * $table->getOption('collation') – not 'collate'.  Because 'collation' is
 * absent, the comparator falls back to SELECT @@character_set_database
 * default, which on MySQL 8 is utf8mb4_0900_ai_ci, not utf8mb4_unicode_ci.
 *
 * If the CHANGE sets an explicit COLLATE utf8mb4_unicode_ci that differs
 * from the DB table's actual collation, normalizeTable() does NOT strip it,
 * and Doctrine keeps emitting the same CHANGE on every dump-sql run.
 *
 * Solution: no explicit charset/collation in CHANGE → column inherits the
 * table's collation → normalizeTable() strips it → zero drift.
 *
 * COMMENT '' is included explicitly to clear any DC2Type comment that may
 * have been left on the column by an older Doctrine migration (required on
 * MariaDB, which preserves comments when COMMENT is omitted from CHANGE).
 */
final class Version20260601000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Resize node_id/agent_id columns to VARCHAR(64) and restore FK constraints.';
    }

    public function up(Schema $schema): void
    {
        // ── Diagnostic: log agents.id reference column state ─────────────────────────────────
        $this->logColumnState('agents', 'id');

        // ── 1. Resize FK columns to VARCHAR(64) and restore FKs ──────────────────────────────
        //
        // $onDelete values: 'CASCADE', 'SET NULL', or '' (no ON DELETE = MySQL RESTRICT default)
        //
        // log_indices.agent_id – nullable, ON DELETE SET NULL
        $this->resizeColumn('log_indices', 'agent_id', true, 'FK_38FA23EC3414710B', 'SET NULL');

        // ON DELETE CASCADE
        foreach ([
            ['sinusbot_nodes',            'agent_id', 'FK_D805E71D3414710B'],
            ['webspace_nodes',            'agent_id', 'FK_F21053CC3414710B'],
            ['security_policy_revisions', 'node_id',  'FK_4209DEF9460D9FD7'],
            ['security_events',           'node_id',  'FK_6568A15F460D9FD7'],
            ['ddos_policies',             'node_id',  'FK_1C7A1358460D9FD7'],
            ['database_nodes',            'agent_id', 'FK_622575053414710B'],
            ['ts3_nodes',                 'agent_id', 'FK_D6D5E7083414710B'],
            ['ddos_statuses',             'node_id',  'FK_B701B287460D9FD7'],
            ['ts6_nodes',                 'agent_id', 'FK_84EDC8AF3414710B'],
        ] as [$table, $column, $fk]) {
            $this->resizeColumn($table, $column, false, $fk, 'CASCADE');
        }

        // No explicit ON DELETE (MySQL RESTRICT default) – FK still required by Doctrine
        foreach ([
            ['port_allocations', 'node_id',  'FK_B1A629A7460D9FD7'],
            ['port_pools',       'node_id',  'FK_44CBF4C6460D9FD7'],
            ['instances',        'node_id',  'FK_7A270069460D9FD7'],
            ['shop_products',    'node_id',  'FK_6802A0CC460D9FD7'],
            ['metric_aggregates','agent_id', 'FK_8450EB023414710B'],
            ['firewall_states',  'node_id',  'FK_7E779082460D9FD7'],
            ['webspaces',        'node_id',  'FK_9A008C7A460D9FD7'],
            ['ts6_instances',    'node_id',  'FK_32034BC9460D9FD7'],
            ['ts3_instances',    'node_id',  'FK_4354E78B460D9FD7'],
        ] as [$table, $column, $fk]) {
            $this->resizeColumn($table, $column, false, $fk, '');
        }
    }

    public function down(Schema $schema): void
    {
        $this->resizeColumnDown('log_indices', 'agent_id', true, 'FK_38FA23EC3414710B', 'SET NULL');

        foreach ([
            ['sinusbot_nodes',            'agent_id', 'FK_D805E71D3414710B'],
            ['webspace_nodes',            'agent_id', 'FK_F21053CC3414710B'],
            ['security_policy_revisions', 'node_id',  'FK_4209DEF9460D9FD7'],
            ['security_events',           'node_id',  'FK_6568A15F460D9FD7'],
            ['ddos_policies',             'node_id',  'FK_1C7A1358460D9FD7'],
            ['database_nodes',            'agent_id', 'FK_622575053414710B'],
            ['ts3_nodes',                 'agent_id', 'FK_D6D5E7083414710B'],
            ['ddos_statuses',             'node_id',  'FK_B701B287460D9FD7'],
            ['ts6_nodes',                 'agent_id', 'FK_84EDC8AF3414710B'],
        ] as [$table, $column, $fk]) {
            $this->resizeColumnDown($table, $column, false, $fk, 'CASCADE');
        }

        foreach ([
            ['port_allocations', 'node_id',  'FK_B1A629A7460D9FD7'],
            ['port_pools',       'node_id',  'FK_44CBF4C6460D9FD7'],
            ['instances',        'node_id',  'FK_7A270069460D9FD7'],
            ['shop_products',    'node_id',  'FK_6802A0CC460D9FD7'],
            ['metric_aggregates','agent_id', 'FK_8450EB023414710B'],
            ['firewall_states',  'node_id',  'FK_7E779082460D9FD7'],
            ['webspaces',        'node_id',  'FK_9A008C7A460D9FD7'],
            ['ts6_instances',    'node_id',  'FK_32034BC9460D9FD7'],
            ['ts3_instances',    'node_id',  'FK_4354E78B460D9FD7'],
        ] as [$table, $column, $fk]) {
            $this->resizeColumnDown($table, $column, false, $fk, '');
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────────────

    /**
     * Resize a node_id / agent_id column to VARCHAR(64) and restore its FK.
     *
     * Charset/collation design:
     *   No explicit CHARACTER SET or COLLATE is included in the CHANGE statement so
     *   the column inherits the table's default collation.  MySQL\Comparator::normalizeTable()
     *   then strips that inherited collation from the comparison, and the ORM-generated
     *   JoinColumn also has no collation → both sides compare as VARCHAR(64) NOT NULL → no drift.
     *
     *   Prerequisites for FK creation (errno 150):
     *   MySQL requires that the referencing column's CHARACTER SET and COLLATION exactly match
     *   the referenced column (agents.id).  If the table's default collation differs from
     *   agents.id's collation, the CHANGE (which inherits the table collation) would produce
     *   a column that cannot be used as a FK.  In that case the table is converted to the
     *   target charset/collation first.  After conversion both table and column carry the same
     *   collation → CHANGE without explicit charset → normalization strips it → no drift.
     *
     *   COMMENT '' explicitly clears legacy DC2Type comments that MariaDB would otherwise
     *   preserve across a CHANGE without a COMMENT clause.
     */
    private function resizeColumn(
        string $table,
        string $column,
        bool   $nullable,
        string $fkName,
        string $onDelete,
    ): void {
        if (!$this->tableExists($table)) {
            $this->write(sprintf('  SKIP %s.%s – table not found', $table, $column));
            return;
        }
        if (!$this->columnExists($table, $column)) {
            $this->write(sprintf('  SKIP %s.%s – column not found', $table, $column));
            return;
        }

        // Log current column state (for diagnosis).
        $this->logColumnState($table, $column);

        // ── Ensure table collation matches agents.id (required for FK creation) ─────────────
        // If the table's collation differs from agents.id's collation, MySQL rejects the FK
        // with errno 150.  Convert the table first so the column can inherit the right collation.
        $agentCharset   = $this->getColumnCharset('agents', 'id');
        $agentCollation = $this->getColumnCollation('agents', 'id');
        $tableCollation = $this->getTableCollation($table);

        if (
            $agentCollation !== null
            && $tableCollation !== null
            && $tableCollation !== $agentCollation
        ) {
            $this->write(sprintf(
                '  Converting %s collation %s → %s to allow FK to agents.id',
                $table, $tableCollation, $agentCollation
            ));
            $this->addSql(sprintf(
                'ALTER TABLE %s CONVERT TO CHARACTER SET %s COLLATE %s',
                $table,
                $agentCharset ?? 'utf8mb4',
                $agentCollation
            ));
        }

        // ── Drop existing FKs before CHANGE ──────────────────────────────────────────────────
        $droppedFk = false;

        if ($this->fkExists($table, $fkName)) {
            $this->write(sprintf('  Dropping FK %s on %s', $fkName, $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $table, $fkName));
            $droppedFk = true;
        }

        $legacyFk = $this->findFkByRelation($table, $column);
        if ($legacyFk !== null && $legacyFk !== $fkName) {
            $this->write(sprintf('  Dropping legacy FK %s on %s', $legacyFk, $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $table, $legacyFk));
            $droppedFk = true;
        }

        // ── CHANGE column ─────────────────────────────────────────────────────────────────────
        // No CHARACTER SET / COLLATE – column inherits from table (which is now aligned with
        // agents.id) → normalization strips it → no drift.
        // COMMENT '' explicitly clears legacy DC2Type comments (safe on MySQL + MariaDB).
        $nullSpec = $nullable ? 'DEFAULT NULL' : 'NOT NULL';
        $this->write(sprintf("  Changing %s.%s → VARCHAR(64) %s COMMENT ''", $table, $column, $nullSpec));
        $this->addSql(sprintf(
            "ALTER TABLE %s CHANGE %s %s VARCHAR(64) %s COMMENT ''",
            $table, $column, $column, $nullSpec
        ));

        // ── ADD CONSTRAINT ────────────────────────────────────────────────────────────────────
        if (!$this->tableExists('agents')) {
            $this->write(sprintf('  SKIP ADD FK on %s.%s – agents table not found', $table, $column));
            return;
        }

        // If we queued a drop above, we know no FK exists after the CHANGE.
        // If no drop was queued, verify via relation scan to avoid duplicate constraint.
        if (!$droppedFk && $this->findFkByRelation($table, $column) !== null) {
            $this->write(sprintf('  SKIP ADD FK on %s.%s – FK already present', $table, $column));
            return;
        }

        if ($onDelete !== '') {
            $this->write(sprintf('  Adding FK %s on %s.%s ON DELETE %s', $fkName, $table, $column, $onDelete));
            $this->addSql(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES agents (id) ON DELETE %s',
                $table, $fkName, $column, $onDelete
            ));
        } else {
            $this->write(sprintf('  Adding FK %s on %s.%s', $fkName, $table, $column));
            $this->addSql(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES agents (id)',
                $table, $fkName, $column
            ));
        }
    }

    /** Reverse of resizeColumn: widens back to VARCHAR(255) for down(). */
    private function resizeColumnDown(
        string $table,
        string $column,
        bool   $nullable,
        string $fkName,
        string $onDelete,
    ): void {
        if (!$this->tableExists($table) || !$this->columnExists($table, $column)) {
            return;
        }

        $droppedFk = false;

        if ($this->fkExists($table, $fkName)) {
            $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $table, $fkName));
            $droppedFk = true;
        }

        $legacyFk = $this->findFkByRelation($table, $column);
        if ($legacyFk !== null && $legacyFk !== $fkName) {
            $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $table, $legacyFk));
            $droppedFk = true;
        }

        $nullSpec = $nullable ? 'DEFAULT NULL' : 'NOT NULL';
        $this->addSql(sprintf(
            "ALTER TABLE %s CHANGE %s %s VARCHAR(255) %s COMMENT ''",
            $table, $column, $column, $nullSpec
        ));

        if (!$this->tableExists('agents')) {
            return;
        }

        if (!$droppedFk && $this->findFkByRelation($table, $column) !== null) {
            return;
        }

        if ($onDelete !== '') {
            $this->addSql(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES agents (id) ON DELETE %s',
                $table, $fkName, $column, $onDelete
            ));
        } else {
            $this->addSql(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES agents (id)',
                $table, $fkName, $column
            ));
        }
    }

    /**
     * Writes the current information_schema.COLUMNS state for $table.$column to the output.
     * Runs immediately (not queued) so the log reflects the pre-CHANGE state.
     */
    private function logColumnState(string $table, string $column): void
    {
        try {
            $row = $this->connection->fetchAssociative(
                "SELECT
                   COLUMN_TYPE,
                   IS_NULLABLE,
                   COLUMN_DEFAULT,
                   CHARACTER_SET_NAME,
                   COLLATION_NAME,
                   COLUMN_COMMENT
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = :table
                   AND COLUMN_NAME  = :column",
                ['table' => $table, 'column' => $column]
            );

            if ($row === false) {
                $this->write(sprintf('  [diag] %s.%s – not found in information_schema', $table, $column));
                return;
            }

            $this->write(sprintf(
                '  [diag] %s.%s | type=%-14s nullable=%-3s default=%-10s charset=%-10s collation=%-26s comment=%s',
                $table,
                $column,
                $row['COLUMN_TYPE'] ?? 'n/a',
                $row['IS_NULLABLE'] ?? 'n/a',
                var_export($row['COLUMN_DEFAULT'], true),
                $row['CHARACTER_SET_NAME'] ?? 'n/a',
                $row['COLLATION_NAME'] ?? 'n/a',
                var_export($row['COLUMN_COMMENT'], true)
            ));
        } catch (\Throwable $e) {
            $this->write(sprintf('  [diag] %s.%s – query failed: %s', $table, $column, $e->getMessage()));
        }
    }

    // ── Schema introspection ─────────────────────────────────────────────────────────────────

    private function tableExists(string $table): bool
    {
        try {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
                ['table' => $table]
            ) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column',
                ['table' => $table, 'column' => $column]
            ) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index',
                ['table' => $table, 'index' => $indexName]
            ) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function fkExists(string $table, string $constraintName): bool
    {
        try {
            return (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME      = :table
                   AND CONSTRAINT_NAME = :constraint
                   AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                ['table' => $table, 'constraint' => $constraintName]
            ) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function getTableCollation(string $table): ?string
    {
        try {
            $value = $this->connection->fetchOne(
                'SELECT TABLE_COLLATION
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
                ['table' => $table]
            );
            return ($value !== false && $value !== null) ? (string) $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function getColumnCharset(string $table, string $column): ?string
    {
        try {
            $value = $this->connection->fetchOne(
                'SELECT CHARACTER_SET_NAME
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column',
                ['table' => $table, 'column' => $column]
            );
            return ($value !== false && $value !== null) ? (string) $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function getColumnCollation(string $table, string $column): ?string
    {
        try {
            $value = $this->connection->fetchOne(
                'SELECT COLLATION_NAME
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column',
                ['table' => $table, 'column' => $column]
            );
            return ($value !== false && $value !== null) ? (string) $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function findFkByRelation(string $table, string $column): ?string
    {
        try {
            $name = $this->connection->fetchOne(
                "SELECT CONSTRAINT_NAME
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA           = DATABASE()
                   AND TABLE_NAME             = :table
                   AND COLUMN_NAME            = :column
                   AND REFERENCED_TABLE_NAME  = 'agents'
                   AND REFERENCED_COLUMN_NAME = 'id'
                 LIMIT 1",
                ['table' => $table, 'column' => $column]
            );

            return ($name !== false && $name !== null) ? (string) $name : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
