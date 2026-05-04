<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260503201547 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        if (!$this->isMySql()) {
            $this->write('Skipping migration on non-MySQL platform.');

            return;
        }
        if (!$this->hasTable('team_groups')) {
            $this->addSql('CREATE TABLE team_groups (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(140) NOT NULL, game VARCHAR(140) NOT NULL, slug VARCHAR(180) NOT NULL, image_path VARCHAR(255) DEFAULT NULL, sort_order INT NOT NULL, site_id INT NOT NULL, INDEX IDX_86767EA9F6BD1646 (site_id), INDEX idx_team_groups_site_sort (site_id, sort_order), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }

        if ($this->hasTable('team_groups') && $this->hasTable('sites') && !$this->hasForeignKey('team_groups', 'FK_86767EA9F6BD1646') && $this->canCreateTeamGroupsSiteForeignKey()) {
            $this->addSql('ALTER TABLE team_groups ADD CONSTRAINT FK_86767EA9F6BD1646 FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        if (!$this->isMySql()) {
            $this->write('Skipping rollback on non-MySQL platform.');

            return;
        }
        if ($this->hasTable('team_groups') && $this->hasForeignKey('team_groups', 'FK_86767EA9F6BD1646')) {
            $this->addSql('ALTER TABLE team_groups DROP FOREIGN KEY FK_86767EA9F6BD1646');
        }
        if ($this->hasTable('team_groups')) {
            $this->addSql('DROP TABLE team_groups');
        }
    }


    private function isMySql(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof MySQLPlatform;
    }

    private function hasTable(string $table): bool
    {
        $database = (string) $this->connection->fetchOne('SELECT DATABASE()');

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, $table],
        ) > 0;
    }

    private function hasForeignKey(string $table, string $foreignKey): bool
    {
        $database = (string) $this->connection->fetchOne('SELECT DATABASE()');

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = \'FOREIGN KEY\'',
            [$database, $table, $foreignKey],
        ) > 0;
    }

    private function canCreateTeamGroupsSiteForeignKey(): bool
    {
        $teamGroupSiteId = $this->getColumnType('team_groups', 'site_id');
        $siteId = $this->getColumnType('sites', 'id');

        return $teamGroupSiteId !== null && $teamGroupSiteId === $siteId;
    }

    private function getColumnType(string $table, string $column): ?string
    {
        $database = (string) $this->connection->fetchOne('SELECT DATABASE()');

        $columnType = $this->connection->fetchOne(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$database, $table, $column],
        );

        return $columnType === false ? null : (string) $columnType;
    }
}
