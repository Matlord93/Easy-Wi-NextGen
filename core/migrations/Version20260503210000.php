<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260503210000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add game column to team_groups.'; }
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('team_groups')) { return; }
        $table = $schema->getTable('team_groups');
        if ($table->hasColumn('game')) { return; }
        $platform = $this->connection->getDatabasePlatform()->getName();
        if ($platform === 'sqlite') {
            $this->addSql("ALTER TABLE team_groups ADD COLUMN game VARCHAR(140) NOT NULL DEFAULT 'Unknown'");
            return;
        }
        $this->addSql("ALTER TABLE team_groups ADD game VARCHAR(140) NOT NULL DEFAULT 'Unknown'");
    }
    public function down(Schema $schema): void { }
}
