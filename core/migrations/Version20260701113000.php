<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260701113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add canonical install_path for gameserver instances.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('instances')) {
            return;
        }

        $table = $schema->getTable('instances');
        if ($table->hasColumn('install_path')) {
            return;
        }

        $this->addSql('ALTER TABLE instances ADD install_path VARCHAR(1024) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('instances')) {
            return;
        }

        $table = $schema->getTable('instances');
        if (!$table->hasColumn('install_path')) {
            return;
        }

        $this->addSql('ALTER TABLE instances DROP install_path');
    }
}
