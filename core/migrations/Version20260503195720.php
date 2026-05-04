<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260503195720 extends AbstractMigration
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
        if ($this->hasTable('contact_messages')) {
            $this->addSql('ALTER TABLE contact_messages CHANGE ip_address ip_address VARCHAR(45) NOT NULL, CHANGE status status VARCHAR(20) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE replied_at replied_at DATETIME DEFAULT NULL');
            if ($this->canCreateContactMessageSiteForeignKey()) {
                $this->addSql('ALTER TABLE contact_messages ADD CONSTRAINT FK_41278201F6BD1646 FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE');
                $this->addSql('CREATE INDEX IDX_41278201F6BD1646 ON contact_messages (site_id)');
            } else {
                $this->write('Skipping FK_41278201F6BD1646 creation because contact_messages.site_id and sites.id are incompatible in this database.');
            }
        }

        if ($this->hasTable('game_template_plugins') && $this->hasColumn('game_template_plugins', 'install_mode')) {
            $this->addSql('ALTER TABLE game_template_plugins CHANGE install_mode install_mode VARCHAR(32) NOT NULL');
        }

        if ($this->hasTable('team_members') && !$this->hasColumn('team_members', 'team_name')) {
            $this->addSql('ALTER TABLE team_members ADD team_name VARCHAR(140) DEFAULT NULL');
        }

        if ($this->hasIndex('user_sessions', 'IDX_7AED7913A76ED395')) {
            $this->addSql('DROP INDEX IDX_7AED7913A76ED395 ON user_sessions');
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        if (!$this->isMySql()) {
            $this->write('Skipping rollback on non-MySQL platform.');

            return;
        }
        if ($this->hasTable('contact_messages')) {
            if ($this->hasForeignKey('contact_messages', 'FK_41278201F6BD1646')) {
                $this->addSql('ALTER TABLE contact_messages DROP FOREIGN KEY FK_41278201F6BD1646');
            }
            if ($this->hasIndex('contact_messages', 'IDX_41278201F6BD1646')) {
                $this->addSql('DROP INDEX IDX_41278201F6BD1646 ON contact_messages');
            }
            $this->addSql('ALTER TABLE contact_messages CHANGE ip_address ip_address VARCHAR(45) DEFAULT \'\' NOT NULL, CHANGE status status VARCHAR(20) DEFAULT \'new\' NOT NULL, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE replied_at replied_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        }

        if ($this->hasTable('game_template_plugins') && $this->hasColumn('game_template_plugins', 'install_mode')) {
            $this->addSql('ALTER TABLE game_template_plugins CHANGE install_mode install_mode VARCHAR(32) DEFAULT \'extract\' NOT NULL');
        }

        if ($this->hasTable('team_members') && $this->hasColumn('team_members', 'team_name')) {
            $this->addSql('ALTER TABLE team_members DROP team_name');
        }

        if ($this->hasTable('user_sessions') && $this->hasColumn('user_sessions', 'user_id') && !$this->hasIndex('user_sessions', 'IDX_7AED7913A76ED395')) {
            $this->addSql('CREATE INDEX IDX_7AED7913A76ED395 ON user_sessions (user_id)');
        }
    }



    private function isMySql(): bool
    {
        return $this->connection->getDatabasePlatform()->getName() == 'mysql';
    }

    private function hasTable(string $table): bool
    {
        $database = (string) $this->connection->fetchOne('SELECT DATABASE()');

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, $table],
        ) > 0;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->getColumnMetadata($table, $column) !== null;
    }

    private function canCreateContactMessageSiteForeignKey(): bool
    {
        $contactSiteId = $this->getColumnMetadata('contact_messages', 'site_id');
        $siteId = $this->getColumnMetadata('sites', 'id');

        if ($contactSiteId === null || $siteId === null) {
            return false;
        }

        return $contactSiteId['column_type'] === $siteId['column_type']
            && $contactSiteId['character_set_name'] === $siteId['character_set_name']
            && $contactSiteId['collation_name'] === $siteId['collation_name'];
    }

    private function getColumnMetadata(string $table, string $column): ?array
    {
        $database = (string) $this->connection->fetchOne('SELECT DATABASE()');

        $result = $this->connection->fetchAssociative(
            'SELECT COLUMN_TYPE AS column_type, CHARACTER_SET_NAME AS character_set_name, COLLATION_NAME AS collation_name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$database, $table, $column],
        );

        return $result === false ? null : $result;
    }

    private function hasForeignKey(string $table, string $foreignKey): bool
    {
        $database = (string) $this->connection->fetchOne('SELECT DATABASE()');

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = \'FOREIGN KEY\'',
            [$database, $table, $foreignKey],
        ) > 0;
    }

    private function hasIndex(string $table, string $index): bool
    {
        $database = (string) $this->connection->fetchOne('SELECT DATABASE()');

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$database, $table, $index],
        ) > 0;
    }
}
