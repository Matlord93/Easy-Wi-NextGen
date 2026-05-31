<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20261002120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add allocation step to port pools for spaced block assignment.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('port_pools')) {
            return;
        }

        $table = $schema->getTable('port_pools');
        if ($table->hasColumn('allocation_step')) {
            return;
        }

        $this->addSql('ALTER TABLE port_pools ADD allocation_step INT NOT NULL DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('port_pools')) {
            return;
        }

        $table = $schema->getTable('port_pools');
        if (!$table->hasColumn('allocation_step')) {
            return;
        }

        $this->addSql('ALTER TABLE port_pools DROP allocation_step');
    }
}
