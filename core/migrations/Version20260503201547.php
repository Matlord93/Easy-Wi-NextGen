<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260503201547 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE team_groups (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(140) NOT NULL, game VARCHAR(140) NOT NULL, slug VARCHAR(180) NOT NULL, image_path VARCHAR(255) DEFAULT NULL, sort_order INT NOT NULL, site_id INT NOT NULL, INDEX IDX_86767EA9F6BD1646 (site_id), INDEX idx_team_groups_site_sort (site_id, sort_order), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE team_groups ADD CONSTRAINT FK_86767EA9F6BD1646 FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE team_groups DROP FOREIGN KEY FK_86767EA9F6BD1646');
        $this->addSql('DROP TABLE team_groups');
    }
}
