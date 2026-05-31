<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260503195500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add missing team_name column to team_members table.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('team_members')) {
            return;
        }

        $table = $schema->getTable('team_members');
        if ($table->hasColumn('team_name')) {
            return;
        }

        $platform = $this->connection->getDatabasePlatform()->getName();

        if ($platform === 'sqlite') {
            $this->addSql('ALTER TABLE team_members ADD COLUMN team_name VARCHAR(140) DEFAULT NULL');
            return;
        }

        $this->addSql("ALTER TABLE team_members ADD team_name VARCHAR(140) DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('team_members')) {
            return;
        }

        $table = $schema->getTable('team_members');
        if (!$table->hasColumn('team_name')) {
            return;
        }

        $platform = $this->connection->getDatabasePlatform()->getName();
        if ($platform === 'sqlite') {
            return;
        }

        $this->addSql('ALTER TABLE team_members DROP COLUMN team_name');
    }
}
