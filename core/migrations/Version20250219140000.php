<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250219140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add plugin catalog entries per game template.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE game_template_plugins (id INT AUTO_INCREMENT NOT NULL, template_id INT NOT NULL, name VARCHAR(160) NOT NULL, version VARCHAR(80) NOT NULL, checksum VARCHAR(128) NOT NULL, download_url VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_game_template_plugins_template (template_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE game_template_plugins ADD CONSTRAINT FK_93368BF95DAF0FB7 FOREIGN KEY (template_id) REFERENCES game_templates (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_template_plugins DROP FOREIGN KEY FK_93368BF95DAF0FB7');
        $this->addSql('DROP TABLE game_template_plugins');
    }
}
