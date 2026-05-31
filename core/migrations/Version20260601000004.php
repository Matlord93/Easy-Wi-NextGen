<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Repairs agents.last_heartbeat_ipv6 that was mistakenly dropped by an earlier
 * version of Version20260601000003.
 *
 * The Agent entity maps this column as VARCHAR(45) DEFAULT NULL, so it must
 * always exist.  This migration is a no-op on databases that still have the
 * column; it only acts when the column is genuinely missing.
 */
final class Version20260601000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Re-add agents.last_heartbeat_ipv6; re-create uq_game_templates_game_key dropped by 00003.';
    }

    public function up(Schema $schema): void
    {
        // ── 1. Re-add agents.last_heartbeat_ipv6 if missing ──────────────────
        if ($this->tableExists('agents') && !$this->columnExists('agents', 'last_heartbeat_ipv6')) {
            $this->write("  Re-adding agents.last_heartbeat_ipv6 VARCHAR(45) DEFAULT NULL");
            $this->addSql("ALTER TABLE agents ADD last_heartbeat_ipv6 VARCHAR(45) DEFAULT NULL COMMENT ''");
        } else {
            $this->write('  agents.last_heartbeat_ipv6 already present – skipping');
        }

        // ── 2. Re-create unique index dropped by Version20260601000003 ────────
        if (!$this->tableExists('game_templates')) {
            $this->write('  SKIP – game_templates table not found');
            return;
        }

        if ($this->indexExists('game_templates', 'uq_game_templates_game_key')) {
            $this->write('  uq_game_templates_game_key already present – skipping');
            return;
        }

        // Deduplicate before creating the unique index (keep row with highest id per game_key)
        $duplicates = $this->connection->fetchAllAssociative(
            'SELECT game_key, MAX(id) AS keep_id
             FROM game_templates
             GROUP BY game_key
             HAVING COUNT(*) > 1'
        );

        if (!empty($duplicates)) {
            $this->write(sprintf('  Found %d duplicate game_key group(s) – deduplicating', count($duplicates)));
            foreach ($duplicates as $dup) {
                $keepId  = (int) $dup['keep_id'];
                $gameKey = (string) $dup['game_key'];

                $dropIds = $this->connection->fetchFirstColumn(
                    'SELECT id FROM game_templates WHERE game_key = :key AND id != :keep',
                    ['key' => $gameKey, 'keep' => $keepId]
                );

                foreach ($dropIds as $rawId) {
                    $dropId = (int) $rawId;
                    if ($this->tableExists('instances')) {
                        $this->addSql('UPDATE instances SET template_id = ? WHERE template_id = ?', [$keepId, $dropId]);
                    }
                    if ($this->tableExists('game_template_plugins')) {
                        $this->addSql('DELETE FROM game_template_plugins WHERE template_id = ?', [$dropId]);
                    }
                    if ($this->tableExists('shop_products')) {
                        $this->addSql('UPDATE shop_products SET template_id = ? WHERE template_id = ?', [$keepId, $dropId]);
                    }
                }
            }
            $this->addSql(
                'DELETE t1 FROM game_templates t1
                 INNER JOIN game_templates t2 ON t1.game_key = t2.game_key AND t1.id < t2.id'
            );
        } else {
            $this->write('  No duplicate game_key values – deduplication not needed');
        }

        $this->write('  Creating uq_game_templates_game_key on game_templates');
        $this->addSql('CREATE UNIQUE INDEX uq_game_templates_game_key ON game_templates (game_key)');
    }

    public function down(Schema $schema): void
    {
        if ($this->tableExists('agents') && $this->columnExists('agents', 'last_heartbeat_ipv6')) {
            $this->addSql('ALTER TABLE agents DROP COLUMN last_heartbeat_ipv6');
        }

        if ($this->tableExists('game_templates') && $this->indexExists('game_templates', 'uq_game_templates_game_key')) {
            $this->addSql('DROP INDEX uq_game_templates_game_key ON game_templates');
        }
    }

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
}
