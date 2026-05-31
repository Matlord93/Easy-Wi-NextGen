<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20250221100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Finalize game template schema and seed initial templates.';
    }

    public function up(Schema $schema): void
    {
        if ($this->isSqlite()) {
            $this->addSql('ALTER TABLE game_templates RENAME COLUMN name TO display_name');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN game_key VARCHAR(80) DEFAULT NULL');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN steam_app_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN sniper_profile VARCHAR(120) DEFAULT NULL');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN env_vars JSON DEFAULT NULL');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN config_files JSON DEFAULT NULL');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN plugin_paths JSON DEFAULT NULL');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN fastdl_settings JSON DEFAULT NULL');
        } else {
            $this->addSql('ALTER TABLE game_templates CHANGE name display_name VARCHAR(120) NOT NULL');
            $this->addSql('ALTER TABLE game_templates ADD game_key VARCHAR(80) DEFAULT NULL, ADD steam_app_id INT DEFAULT NULL, ADD sniper_profile VARCHAR(120) DEFAULT NULL, ADD env_vars JSON DEFAULT NULL, ADD config_files JSON DEFAULT NULL, ADD plugin_paths JSON DEFAULT NULL, ADD fastdl_settings JSON DEFAULT NULL');
        }

        $this->addSql(sprintf(
            'UPDATE game_templates SET game_key = %s WHERE game_key IS NULL',
            $this->isSqlite() ? "'legacy-' || id" : "CONCAT('legacy-', id)"
        ));
        $this->addSql("UPDATE game_templates SET env_vars = '[]' WHERE env_vars IS NULL");
        $this->addSql("UPDATE game_templates SET config_files = '[]' WHERE config_files IS NULL");
        $this->addSql("UPDATE game_templates SET plugin_paths = '[]' WHERE plugin_paths IS NULL");
        $this->addSql("UPDATE game_templates SET fastdl_settings = '{}' WHERE fastdl_settings IS NULL");

        if (!$this->isSqlite()) {
            $this->addSql('ALTER TABLE game_templates MODIFY game_key VARCHAR(80) NOT NULL');
            $this->addSql('ALTER TABLE game_templates MODIFY env_vars JSON NOT NULL');
            $this->addSql('ALTER TABLE game_templates MODIFY config_files JSON NOT NULL');
            $this->addSql('ALTER TABLE game_templates MODIFY plugin_paths JSON NOT NULL');
            $this->addSql('ALTER TABLE game_templates MODIFY fastdl_settings JSON NOT NULL');
        }
        // Template seeds moved to GameTemplateSeeder.
    }

    public function down(Schema $schema): void
    {
        if ($this->isSqlite()) {
            $this->addSql('ALTER TABLE game_templates DROP COLUMN game_key');
            $this->addSql('ALTER TABLE game_templates DROP COLUMN steam_app_id');
            $this->addSql('ALTER TABLE game_templates DROP COLUMN sniper_profile');
            $this->addSql('ALTER TABLE game_templates DROP COLUMN env_vars');
            $this->addSql('ALTER TABLE game_templates DROP COLUMN config_files');
            $this->addSql('ALTER TABLE game_templates DROP COLUMN plugin_paths');
            $this->addSql('ALTER TABLE game_templates DROP COLUMN fastdl_settings');
            $this->addSql('ALTER TABLE game_templates RENAME COLUMN display_name TO name');
        } else {
            $this->addSql('ALTER TABLE game_templates DROP game_key, DROP steam_app_id, DROP sniper_profile, DROP env_vars, DROP config_files, DROP plugin_paths, DROP fastdl_settings');
            $this->addSql('ALTER TABLE game_templates CHANGE display_name name VARCHAR(120) NOT NULL');
        }
    }

    private function isSqlite(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform;
    }
}
