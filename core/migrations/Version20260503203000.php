<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260503203000 extends AbstractMigration
{
    public function getDescription(): string { return 'Create team_groups table for team landing and detail pages.'; }
    public function up(Schema $schema): void
    {
        if ($schema->hasTable('team_groups')) { return; }
        $platform = $this->connection->getDatabasePlatform()->getName();
        if ($platform === 'sqlite') {
            $this->addSql("CREATE TABLE team_groups (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, site_id INTEGER NOT NULL, name VARCHAR(140) NOT NULL, game VARCHAR(140) NOT NULL DEFAULT 'Unknown', slug VARCHAR(180) NOT NULL, image_path VARCHAR(255) DEFAULT NULL, sort_order INTEGER NOT NULL DEFAULT 0, CONSTRAINT FK_TEAM_GROUPS_SITE FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE)");
            $this->addSql('CREATE INDEX idx_team_groups_site_sort ON team_groups (site_id, sort_order)');
            return;
        }
        $this->addSql("CREATE TABLE team_groups (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, name VARCHAR(140) NOT NULL, game VARCHAR(140) NOT NULL DEFAULT 'Unknown', slug VARCHAR(180) NOT NULL, image_path VARCHAR(255) DEFAULT NULL, sort_order INT NOT NULL DEFAULT 0, INDEX idx_team_groups_site_sort (site_id, sort_order), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE team_groups ADD CONSTRAINT FK_TEAM_GROUPS_SITE FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE');
    }
    public function down(Schema $schema): void { if ($schema->hasTable('team_groups')) { $this->addSql('DROP TABLE team_groups'); } }
}
