<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260119120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename server_sftp_access keys column to ssh_keys.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('server_sftp_access')) {
            return;
        }

        $table = $schema->getTable('server_sftp_access');
        if (!$table->hasColumn('keys') || $table->hasColumn('ssh_keys')) {
            return;
        }

        $this->addSql('ALTER TABLE server_sftp_access CHANGE `keys` ssh_keys JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('server_sftp_access')) {
            return;
        }

        $table = $schema->getTable('server_sftp_access');
        if (!$table->hasColumn('ssh_keys') || $table->hasColumn('keys')) {
            return;
        }

        $this->addSql('ALTER TABLE server_sftp_access CHANGE ssh_keys `keys` JSON NOT NULL');
    }
}
