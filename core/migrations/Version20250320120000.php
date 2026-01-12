<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250320120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add game definitions and config schemas.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('game_definitions')) {
            $this->addSql('CREATE TABLE game_definitions (id INT AUTO_INCREMENT NOT NULL, game_key VARCHAR(80) NOT NULL, display_name VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_game_definitions_key (game_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$schema->hasTable('config_schemas')) {
            $this->addSql('CREATE TABLE config_schemas (id INT AUTO_INCREMENT NOT NULL, game_definition_id INT NOT NULL, config_key VARCHAR(80) NOT NULL, name VARCHAR(160) NOT NULL, format VARCHAR(32) NOT NULL, file_path VARCHAR(255) NOT NULL, schema JSON NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_config_schemas_game (game_definition_id), UNIQUE INDEX uniq_config_schemas_game_key (game_definition_id, config_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE config_schemas ADD CONSTRAINT fk_config_schemas_game_definition FOREIGN KEY (game_definition_id) REFERENCES game_definitions (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('config_schemas')) {
            $this->addSql('ALTER TABLE config_schemas DROP FOREIGN KEY fk_config_schemas_game_definition');
            $this->addSql('DROP TABLE config_schemas');
        }

        if ($schema->hasTable('game_definitions')) {
            $this->addSql('DROP TABLE game_definitions');
        }
    }
}
