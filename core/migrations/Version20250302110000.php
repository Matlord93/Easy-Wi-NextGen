<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20250302110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add running status to sinusbot nodes.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('sinusbot_nodes')) {
            return;
        }

        $table = $schema->getTable('sinusbot_nodes');
        if ($table->hasColumn('running')) {
            return;
        }

        $this->addSql('ALTER TABLE sinusbot_nodes ADD running TINYINT(1) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('sinusbot_nodes')) {
            return;
        }

        $table = $schema->getTable('sinusbot_nodes');
        if (!$table->hasColumn('running')) {
            return;
        }

        $this->addSql('ALTER TABLE sinusbot_nodes DROP running');
    }
}
