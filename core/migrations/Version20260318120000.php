<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260318120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin SSH public key to users.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }

        $table = $schema->getTable('users');
        if ($table->hasColumn('admin_ssh_public_key')) {
            return;
        }

        $this->addSql('ALTER TABLE users ADD admin_ssh_public_key LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }

        $table = $schema->getTable('users');
        if (!$table->hasColumn('admin_ssh_public_key')) {
            return;
        }

        $this->addSql('ALTER TABLE users DROP admin_ssh_public_key');
    }
}
