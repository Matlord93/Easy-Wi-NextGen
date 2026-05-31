<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20270227195500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill optional columns backend and allocation_step for legacy installations.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('instance_sftp_credentials')) {
            $table = $schema->getTable('instance_sftp_credentials');
            if (!$table->hasColumn('backend')) {
                $this->addSql("ALTER TABLE instance_sftp_credentials ADD backend VARCHAR(32) NOT NULL DEFAULT 'NONE'");
            }
        }

        if ($schema->hasTable('port_pools')) {
            $table = $schema->getTable('port_pools');
            if (!$table->hasColumn('allocation_step')) {
                $this->addSql('ALTER TABLE port_pools ADD allocation_step INT NOT NULL DEFAULT 1');
            }
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('port_pools')) {
            $table = $schema->getTable('port_pools');
            if ($table->hasColumn('allocation_step')) {
                $this->addSql('ALTER TABLE port_pools DROP allocation_step');
            }
        }

        if ($schema->hasTable('instance_sftp_credentials')) {
            $table = $schema->getTable('instance_sftp_credentials');
            if ($table->hasColumn('backend')) {
                $this->addSql('ALTER TABLE instance_sftp_credentials DROP backend');
            }
        }
    }
}
