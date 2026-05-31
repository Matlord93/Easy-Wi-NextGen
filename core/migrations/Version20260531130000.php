<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260531130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Repair game template keys/schema, agent job concurrency, and enforce current plugin catalog uniqueness.';
    }

    public function up(Schema $schema): void
    {
        $this->repairAgentJobConcurrency($schema);

        if (!$schema->hasTable('game_templates')) {
            return;
        }

        $isSqlite = $this->connection->getDatabasePlatform() instanceof SQLitePlatform;
        $this->ensureGameTemplateColumns($schema, $isSqlite);
        $this->repairTemplateKeys();
        $this->deduplicateTemplateKeys($isSqlite);
        $this->ensureGameTemplateGameKeyConstraint($schema, $isSqlite);
        $this->ensureGamePluginUniqueIndex($schema, $isSqlite);
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('game_plugins') && $schema->getTable('game_plugins')->hasIndex('uq_game_plugins_template_name_version')) {
            $this->addSql($this->connection->getDatabasePlatform() instanceof SQLitePlatform
                ? 'DROP INDEX uq_game_plugins_template_name_version'
                : 'DROP INDEX uq_game_plugins_template_name_version ON game_plugins'
            );
        }
    }

    private function repairAgentJobConcurrency(Schema $schema): void
    {
        if (!$schema->hasTable('agents')) {
            return;
        }

        $table = $schema->getTable('agents');
        if (!$table->hasColumn('job_concurrency')) {
            return;
        }

        $this->addSql('UPDATE agents SET job_concurrency = 1 WHERE job_concurrency IS NULL');

        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }

        $this->addSql('ALTER TABLE agents CHANGE job_concurrency job_concurrency INT NOT NULL');
    }

    private function ensureGameTemplateColumns(Schema $schema, bool $isSqlite): void
    {
        $table = $schema->getTable('game_templates');

        if (!$table->hasColumn('display_name')) {
            if ($table->hasColumn('name')) {
                $this->addSql($isSqlite ? 'ALTER TABLE game_templates ADD COLUMN display_name VARCHAR(120) DEFAULT NULL' : 'ALTER TABLE game_templates ADD display_name VARCHAR(120) DEFAULT NULL');
                $this->addSql('UPDATE game_templates SET display_name = name WHERE display_name IS NULL OR display_name = ' . $this->connection->quote(''));
            } else {
                $this->addSql($isSqlite ? 'ALTER TABLE game_templates ADD COLUMN display_name VARCHAR(120) DEFAULT NULL' : 'ALTER TABLE game_templates ADD display_name VARCHAR(120) DEFAULT NULL');
            }
        }

        $columns = [
            'game_key' => 'VARCHAR(80) DEFAULT NULL',
            'description' => $isSqlite ? 'CLOB DEFAULT NULL' : 'LONGTEXT DEFAULT NULL',
            'steam_app_id' => 'INT DEFAULT NULL',
            'sniper_profile' => 'VARCHAR(120) DEFAULT NULL',
            'required_ports' => $isSqlite ? "CLOB NOT NULL DEFAULT '[]'" : "JSON NOT NULL DEFAULT ('[]')",
            'start_params' => $isSqlite ? "CLOB NOT NULL DEFAULT ''" : "LONGTEXT NOT NULL DEFAULT ('')",
            'install_command' => $isSqlite ? "CLOB NOT NULL DEFAULT ''" : "LONGTEXT NOT NULL DEFAULT ('')",
            'update_command' => $isSqlite ? "CLOB NOT NULL DEFAULT ''" : "LONGTEXT NOT NULL DEFAULT ('')",
            'allowed_switch_flags' => $isSqlite ? "CLOB NOT NULL DEFAULT '[]'" : "JSON NOT NULL DEFAULT ('[]')",
            'env_vars' => $isSqlite ? "CLOB NOT NULL DEFAULT '[]'" : "JSON NOT NULL DEFAULT ('[]')",
            'config_files' => $isSqlite ? "CLOB NOT NULL DEFAULT '[]'" : "JSON NOT NULL DEFAULT ('[]')",
            'plugin_paths' => $isSqlite ? "CLOB NOT NULL DEFAULT '[]'" : "JSON NOT NULL DEFAULT ('[]')",
            'fastdl_settings' => $isSqlite ? "CLOB NOT NULL DEFAULT '{}'" : "JSON NOT NULL DEFAULT ('{}')",
            'install_resolver' => $isSqlite ? "CLOB NOT NULL DEFAULT '{}'" : "JSON NOT NULL DEFAULT ('{}')",
            'requirement_vars' => $isSqlite ? "CLOB NOT NULL DEFAULT '[]'" : "JSON NOT NULL DEFAULT ('[]')",
            'requirement_secrets' => $isSqlite ? "CLOB NOT NULL DEFAULT '[]'" : "JSON NOT NULL DEFAULT ('[]')",
            'supported_os' => $isSqlite ? "CLOB NOT NULL DEFAULT '[\"linux\"]'" : "JSON NOT NULL DEFAULT ('[\"linux\"]')",
            'port_profile' => $isSqlite ? "CLOB NOT NULL DEFAULT '[]'" : "JSON NOT NULL DEFAULT ('[]')",
            'requirements' => $isSqlite ? "CLOB NOT NULL DEFAULT '{}'" : "JSON NOT NULL DEFAULT ('{}')",
            'created_at' => 'DATETIME DEFAULT NULL',
            'updated_at' => 'DATETIME DEFAULT NULL',
        ];

        foreach ($columns as $column => $definition) {
            if (!$table->hasColumn($column)) {
                $this->addSql(sprintf('ALTER TABLE game_templates ADD %s %s', $column, $definition));
            }
        }

        $now = $isSqlite ? "datetime('now')" : 'CURRENT_TIMESTAMP';
        $this->addSql($isSqlite ? "UPDATE game_templates SET display_name = COALESCE(NULLIF(display_name, ''), 'Template ' || id) WHERE display_name IS NULL OR display_name = ''" : "UPDATE game_templates SET display_name = COALESCE(NULLIF(display_name, ''), CONCAT('Template ', id)) WHERE display_name IS NULL OR display_name = ''");
        $this->addSql("UPDATE game_templates SET created_at = {$now} WHERE created_at IS NULL");
        $this->addSql("UPDATE game_templates SET updated_at = {$now} WHERE updated_at IS NULL");
    }

    private function repairTemplateKeys(): void
    {
        $mappings = [
            730 => 'cs2', 740 => 'csgo_legacy', 258550 => 'rust', 896660 => 'valheim', 2394010 => 'palworld', 294420 => 'seven_days_to_die', 4020 => 'garrys_mod', 232250 => 'tf2', 232330 => 'css', 232370 => 'hl2dm', 232290 => 'dods', 222860 => 'left4dead2', 222840 => 'left4dead', 105600 => 'terraria',
        ];
        foreach ($mappings as $steamAppId => $gameKey) {
            $this->addSql(sprintf("UPDATE game_templates SET game_key = '%s' WHERE steam_app_id = %d AND (game_key IS NULL OR game_key = '' OR game_key LIKE 'legacy-%%')", $gameKey, $steamAppId));
        }

        $nameMappings = [
            'minecraft' => 'minecraft', 'paper' => 'minecraft_paper_all', 'counter-strike 2' => 'cs2', 'counter strike 2' => 'cs2', 'cs2' => 'cs2', 'valheim' => 'valheim', 'rust' => 'rust', 'palworld' => 'palworld', 'garry' => 'garrys_mod', 'team fortress 2' => 'tf2', 'terraria' => 'terraria', '7 days' => 'seven_days_to_die',
        ];
        foreach ($nameMappings as $needle => $gameKey) {
            $quotedNeedle = $this->connection->quote('%'.$needle.'%');
            $quotedKey = $this->connection->quote($gameKey);
            $this->addSql("UPDATE game_templates SET game_key = {$quotedKey} WHERE LOWER(display_name) LIKE {$quotedNeedle} AND (game_key IS NULL OR game_key = '' OR game_key LIKE 'legacy-%')");
        }

        $concatLegacy = $this->connection->getDatabasePlatform() instanceof SQLitePlatform ? "'legacy-' || id" : "CONCAT('legacy-', id)";
        $this->addSql("UPDATE game_templates SET game_key = {$concatLegacy} WHERE game_key IS NULL OR game_key = ''");
        $this->addSql("UPDATE game_templates SET game_key = LOWER(TRIM(game_key))");
    }

    private function deduplicateTemplateKeys(bool $isSqlite): void
    {
        if ($isSqlite) {
            $this->addSql("UPDATE game_templates SET game_key = game_key || '-legacy-' || id WHERE id NOT IN (SELECT MIN(id) FROM game_templates GROUP BY game_key)");
            return;
        }

        $this->addSql("UPDATE game_templates gt INNER JOIN (SELECT game_key, MIN(id) keep_id FROM game_templates GROUP BY game_key HAVING COUNT(*) > 1) duplicates ON duplicates.game_key = gt.game_key SET gt.game_key = CONCAT(gt.game_key, '-legacy-', gt.id) WHERE gt.id <> duplicates.keep_id");
    }

    private function ensureGameTemplateGameKeyConstraint(Schema $schema, bool $isSqlite): void
    {
        if ($isSqlite) {
            return;
        }

        $table = $schema->getTable('game_templates');
        if (!$table->hasIndex('uq_game_templates_game_key')) {
            $this->addSql('CREATE UNIQUE INDEX uq_game_templates_game_key ON game_templates (game_key)');
        }
        $this->addSql('ALTER TABLE game_templates MODIFY game_key VARCHAR(80) NOT NULL');
        $this->addSql('ALTER TABLE game_templates MODIFY display_name VARCHAR(120) NOT NULL');
    }

    private function ensureGamePluginUniqueIndex(Schema $schema, bool $isSqlite): void
    {
        if (!$schema->hasTable('game_plugins')) {
            return;
        }

        $duplicates = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM (SELECT template_id, LOWER(name) normalized_name, version FROM game_plugins GROUP BY template_id, LOWER(name), version HAVING COUNT(*) > 1) duplicates');
        if ($duplicates > 0) {
            $this->write('game_plugins contains duplicate template/name/version entries; unique index was not added. Run plugin seeder/import repair first.');
            return;
        }

        $table = $schema->getTable('game_plugins');
        if (!$table->hasIndex('uq_game_plugins_template_name_version')) {
            $this->addSql($isSqlite
                ? 'CREATE UNIQUE INDEX uq_game_plugins_template_name_version ON game_plugins (template_id, name, version)'
                : 'CREATE UNIQUE INDEX uq_game_plugins_template_name_version ON game_plugins (template_id, name, version)'
            );
        }
    }
}
