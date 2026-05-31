<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260318121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin SSH key enablement flag.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }

        $table = $schema->getTable('users');
        if ($table->hasColumn('admin_ssh_key_enabled')) {
            return;
        }

        $this->addSql('ALTER TABLE users ADD admin_ssh_key_enabled TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }

        $table = $schema->getTable('users');
        if (!$table->hasColumn('admin_ssh_key_enabled')) {
            return;
        }

        $this->addSql('ALTER TABLE users DROP admin_ssh_key_enabled');
    }
}
