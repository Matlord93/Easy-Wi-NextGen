<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds missing FK constraints (node_id / agent_id → agents.id) for all affected tables and
 * renames the agent_jobs backing index to match the current Doctrine naming strategy.
 *
 * Orphan strategy (per ON DELETE rule in the entity mapping):
 *   ON DELETE SET NULL  → orphaned FK values are set to NULL before the constraint is added.
 *   ON DELETE CASCADE   → orphaned child rows are deleted before the constraint is added.
 *   (no ON DELETE)      → migration aborts with a human-readable message if orphaned rows exist;
 *                         manual data cleanup is required before re-running the update.
 *
 * Every FK and the index rename are guarded so the migration is safe to re-run (idempotent).
 * Guards check both the exact constraint name AND any existing FK on the same relation, so
 * pre-existing constraints with different names are not duplicated.
 */
final class Version20260601000002 extends AbstractMigration
{
    // Index names for the agent_jobs.node_id backing index.
    // AJ_IDX_OLD = name written by Version20260527162740.
    // AJ_IDX_NEW = name expected by the current Doctrine naming strategy.
    private const AJ_IDX_OLD = 'IDX_2789AA3C5C1662B';
    private const AJ_IDX_NEW = 'IDX_508D726460D9FD7';

    public function getDescription(): string
    {
        return 'Add missing FK constraints (node_id/agent_id → agents) for all affected tables; rename agent_jobs backing index.';
    }

    public function up(Schema $schema): void
    {
        // ── 1. agent_jobs: rename backing index to match current Doctrine naming ──────────────
        if (
            $this->tableExists('agent_jobs')
            && $this->indexExists('agent_jobs', self::AJ_IDX_OLD)
            && !$this->indexExists('agent_jobs', self::AJ_IDX_NEW)
        ) {
            $this->addSql(sprintf(
                'ALTER TABLE agent_jobs RENAME INDEX %s TO %s',
                self::AJ_IDX_OLD,
                self::AJ_IDX_NEW
            ));
        }

        // ── 1b. Normalise agents.id collation so every subsequent FK addition succeeds
        //        regardless of what collation the column was created with originally.
        if ($this->tableExists('agents') && $this->columnExists('agents', 'id')) {
            $this->addSql(
                'ALTER TABLE agents MODIFY id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL'
            );
        }

        // ── 2. ON DELETE SET NULL ─────────────────────────────────────────────────────────────
        $this->addFkSetNull('log_indices', 'agent_id', 'FK_38FA23EC3414710B');

        // ── 3. ON DELETE CASCADE ─────────────────────────────────────────────────────────────
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
        ] as [$table, $column, $constraint]) {
            $this->addFkCascade($table, $column, $constraint);
        }

        // ── 4. No ON DELETE (RESTRICT) ── abort if orphans exist ─────────────────────────────
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
        ] as [$table, $column, $constraint]) {
            $this->addFkRestrict($table, $column, $constraint);
        }
    }

    public function down(Schema $schema): void
    {
        // Drop only the FKs that this migration added (identified by their exact constraint name).
        foreach ([
            ['log_indices',               'FK_38FA23EC3414710B'],
            ['sinusbot_nodes',            'FK_D805E71D3414710B'],
            ['webspace_nodes',            'FK_F21053CC3414710B'],
            ['security_policy_revisions', 'FK_4209DEF9460D9FD7'],
            ['security_events',           'FK_6568A15F460D9FD7'],
            ['ddos_policies',             'FK_1C7A1358460D9FD7'],
            ['database_nodes',            'FK_622575053414710B'],
            ['ts3_nodes',                 'FK_D6D5E7083414710B'],
            ['ddos_statuses',             'FK_B701B287460D9FD7'],
            ['ts6_nodes',                 'FK_84EDC8AF3414710B'],
            ['port_allocations',          'FK_B1A629A7460D9FD7'],
            ['port_pools',                'FK_44CBF4C6460D9FD7'],
            ['instances',                 'FK_7A270069460D9FD7'],
            ['shop_products',             'FK_6802A0CC460D9FD7'],
            ['metric_aggregates',         'FK_8450EB023414710B'],
            ['firewall_states',           'FK_7E779082460D9FD7'],
            ['webspaces',                 'FK_9A008C7A460D9FD7'],
            ['ts6_instances',             'FK_32034BC9460D9FD7'],
            ['ts3_instances',             'FK_4354E78B460D9FD7'],
        ] as [$table, $constraint]) {
            if ($this->tableExists($table) && $this->fkExists($table, $constraint)) {
                $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $table, $constraint));
            }
        }

        // Rename backing index back to original name.
        if (
            $this->tableExists('agent_jobs')
            && $this->indexExists('agent_jobs', self::AJ_IDX_NEW)
            && !$this->indexExists('agent_jobs', self::AJ_IDX_OLD)
        ) {
            $this->addSql(sprintf(
                'ALTER TABLE agent_jobs RENAME INDEX %s TO %s',
                self::AJ_IDX_NEW,
                self::AJ_IDX_OLD
            ));
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────────────

    /**
     * Add an ON DELETE SET NULL FK.
     * Nulls out orphaned values first so the constraint can be applied cleanly.
     */
    private function addFkSetNull(string $table, string $column, string $constraint): void
    {
        if (!$this->tableExists($table)) {
            return;
        }
        if (!$this->tableExists('agents')) {
            return;
        }
        if (!$this->columnExists($table, $column)) {
            return;
        }
        if ($this->fkExists($table, $constraint) || $this->fkOnRelationExists($table, $column)) {
            return;
        }

        $this->addSql(sprintf(
            'UPDATE %s SET %s = NULL WHERE %s IS NOT NULL'
            . ' AND CONVERT(%s USING utf8mb4) COLLATE utf8mb4_unicode_ci'
            . ' NOT IN (SELECT CONVERT(id USING utf8mb4) COLLATE utf8mb4_unicode_ci FROM agents)',
            $table, $column, $column, $column
        ));

        $this->normaliseColumnCollation($table, $column);
        $this->addSql(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES agents (id) ON DELETE SET NULL',
            $table, $constraint, $column
        ));
    }

    /**
     * Add an ON DELETE CASCADE FK.
     * Deletes orphaned child rows first (mirrors what CASCADE would do going forward).
     */
    private function addFkCascade(string $table, string $column, string $constraint): void
    {
        if (!$this->tableExists($table)) {
            return;
        }
        if (!$this->tableExists('agents')) {
            return;
        }
        if (!$this->columnExists($table, $column)) {
            return;
        }
        if ($this->fkExists($table, $constraint) || $this->fkOnRelationExists($table, $column)) {
            return;
        }

        $this->addSql(sprintf(
            'DELETE FROM %s WHERE %s IS NOT NULL'
            . ' AND CONVERT(%s USING utf8mb4) COLLATE utf8mb4_unicode_ci'
            . ' NOT IN (SELECT CONVERT(id USING utf8mb4) COLLATE utf8mb4_unicode_ci FROM agents)',
            $table, $column, $column
        ));

        $this->normaliseColumnCollation($table, $column);
        $this->addSql(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES agents (id) ON DELETE CASCADE',
            $table, $constraint, $column
        ));
    }

    /**
     * Add a RESTRICT FK (the MySQL/MariaDB default when ON DELETE is omitted).
     * Aborts the migration with a clear message if orphaned rows are found; no data is modified.
     */
    private function addFkRestrict(string $table, string $column, string $constraint): void
    {
        if (!$this->tableExists($table)) {
            return;
        }
        if (!$this->tableExists('agents')) {
            return;
        }
        if (!$this->columnExists($table, $column)) {
            return;
        }
        if ($this->fkExists($table, $constraint) || $this->fkOnRelationExists($table, $column)) {
            return;
        }

        $orphans = (int) $this->connection->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE %s IS NOT NULL'
            . ' AND CONVERT(%s USING utf8mb4) COLLATE utf8mb4_unicode_ci'
            . ' NOT IN (SELECT CONVERT(id USING utf8mb4) COLLATE utf8mb4_unicode_ci FROM agents)',
            $table, $column, $column
        ));

        $this->abortIf(
            $orphans > 0,
            sprintf(
                'Cannot add FK constraint %s on %s.%s → agents.id: %d orphaned row(s) found '
                . 'whose %s value does not match any agents.id. '
                . 'Please reassign or delete these rows manually, then re-run the update.',
                $constraint, $table, $column, $orphans, $column
            )
        );

        $this->normaliseColumnCollation($table, $column);
        $this->addSql(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES agents (id)',
            $table, $constraint, $column
        ));
    }

    // ── Schema introspection ─────────────────────────────────────────────────────────────────
    //
    // Every string comparison wraps BOTH sides in
    //   CONVERT(expr USING utf8mb4) COLLATE utf8mb4_unicode_ci
    // This eliminates SQLSTATE HY000 / 1267 "Illegal mix of collations" regardless of whether
    // the server default is utf8mb4_general_ci or utf8mb4_unicode_ci, and works on both MySQL
    // and MariaDB.  The rule applies to:
    //   • information_schema column references (TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, …)
    //   • DATABASE() – scalar function, treated the same way
    //   • bound parameters (:table, :column, …) – CONVERT(? USING utf8mb4) is valid in both
    //     MySQL and MariaDB prepared statements
    //   • string literals ('FOREIGN KEY', 'agents', 'id')

    private function tableExists(string $table): bool
    {
        try {
            $count = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE CONVERT(TABLE_SCHEMA USING utf8mb4) COLLATE utf8mb4_unicode_ci
                       = CONVERT(DATABASE() USING utf8mb4) COLLATE utf8mb4_unicode_ci
                   AND CONVERT(TABLE_NAME USING utf8mb4) COLLATE utf8mb4_unicode_ci
                       = CONVERT(:table USING utf8mb4) COLLATE utf8mb4_unicode_ci',
                ['table' => $table]
            );

            return $count > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Returns true if $column exists in $table (case-insensitive). */
    private function columnExists(string $table, string $column): bool
    {
        try {
            $count = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE CONVERT(TABLE_SCHEMA USING utf8mb4) COLLATE utf8mb4_unicode_ci
                       = CONVERT(DATABASE() USING utf8mb4) COLLATE utf8mb4_unicode_ci
                   AND CONVERT(TABLE_NAME  USING utf8mb4) COLLATE utf8mb4_unicode_ci
                       = CONVERT(:table    USING utf8mb4) COLLATE utf8mb4_unicode_ci
                   AND CONVERT(COLUMN_NAME USING utf8mb4) COLLATE utf8mb4_unicode_ci
                       = CONVERT(:column   USING utf8mb4) COLLATE utf8mb4_unicode_ci',
                ['table' => $table, 'column' => $column]
            );

            return $count > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            $count = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE CONVERT(TABLE_SCHEMA USING utf8mb4) COLLATE utf8mb4_unicode_ci
                       = CONVERT(DATABASE() USING utf8mb4) COLLATE utf8mb4_unicode_ci
                   AND CONVERT(TABLE_NAME  USING utf8mb4) COLLATE utf8mb4_unicode_ci
                       = CONVERT(:table    USING utf8mb4) COLLATE utf8mb4_unicode_ci
                   AND CONVERT(INDEX_NAME  USING utf8mb4) COLLATE utf8mb4_unicode_ci
                       = CONVERT(:index    USING utf8mb4) COLLATE utf8mb4_unicode_ci',
                ['table' => $table, 'index' => $indexName]
            );

            return $count > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Returns true if a FK constraint with exactly the given name exists on $table. */
    private function fkExists(string $table, string $constraintName): bool
    {
        try {
            $count = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONVERT(TABLE_SCHEMA    USING utf8mb4) COLLATE utf8mb4_unicode_ci
                       = CONVERT(DATABASE()   USING utf8mb4) COLLATE utf8mb4_unicode_ci
                   AND CONVERT(TABLE_NAME      USING utf8mb4) COLLATE utf8mb4_unicode_ci
                       = CONVERT(:table        USING utf8mb4) COLLATE utf8mb4_unicode_ci
                   AND CONVERT(CONSTRAINT_NAME USING utf8mb4) COLLATE utf8mb4_unicode_ci
                       = CONVERT(:constraint   USING utf8mb4) COLLATE utf8mb4_unicode_ci
                   AND CONVERT(CONSTRAINT_TYPE USING utf8mb4) COLLATE utf8mb4_unicode_ci
                       = CONVERT(\'FOREIGN KEY\' USING utf8mb4) COLLATE utf8mb4_unicode_ci',
                ['table' => $table, 'constraint' => $constraintName]
            );

            return $count > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Returns true if any FK on $table already covers the relation $column → agents.id,
     * regardless of the constraint name. Prevents duplicate FKs when the existing constraint
     * was created with a different (e.g. legacy) name.
     *
     * Queries KEY_COLUMN_USAGE directly (no JOIN to TABLE_CONSTRAINTS); rows with a non-null
     * REFERENCED_TABLE_NAME are always FK references, making the join redundant.
     * Plain = comparisons are used intentionally: information_schema metadata columns do not
     * suffer the utf8mb4_general_ci / utf8mb4_unicode_ci mismatch that affects data columns.
     */
    private function fkOnRelationExists(string $table, string $column): bool
    {
        try {
            $count = (int) $this->connection->fetchOne(
                "SELECT COUNT(*)
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table
                   AND COLUMN_NAME = :column
                   AND REFERENCED_TABLE_NAME = 'agents'
                   AND REFERENCED_COLUMN_NAME = 'id'",
                ['table' => $table, 'column' => $column]
            );

            return $count > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Reads the child column's type and nullability from information_schema, then queues an
     * ALTER TABLE MODIFY COLUMN that forces CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci.
     * This eliminates errno 150 "Foreign key constraint is incorrectly formed" caused by a
     * collation mismatch between the child column and agents.id (utf8mb4_unicode_ci).
     * A no-op if the column is already the correct collation or is not a character type.
     */
    private function normaliseColumnCollation(string $table, string $column): void
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table
                   AND COLUMN_NAME = :column',
                ['table' => $table, 'column' => $column]
            );

            if (!$row) {
                return;
            }

            $dataType = strtolower((string) $row['DATA_TYPE']);
            if (!in_array($dataType, ['varchar', 'char', 'tinytext', 'text', 'mediumtext', 'longtext'], true)) {
                return;
            }

            $typeSpec = strtoupper($dataType);
            if ($row['CHARACTER_MAXIMUM_LENGTH'] !== null) {
                $typeSpec .= '(' . (int) $row['CHARACTER_MAXIMUM_LENGTH'] . ')';
            }
            $nullSpec = strtolower((string) $row['IS_NULLABLE']) === 'yes' ? 'DEFAULT NULL' : 'NOT NULL';

            $this->addSql(sprintf(
                'ALTER TABLE %s MODIFY COLUMN %s %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci %s',
                $table, $column, $typeSpec, $nullSpec
            ));
        } catch (\Throwable) {
            // Cannot determine column type – skip normalisation silently
        }
    }
}
