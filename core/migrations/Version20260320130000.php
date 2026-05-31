<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260320130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pending admin SSH public key storage.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }

        $table = $schema->getTable('users');
        if ($table->hasColumn('admin_ssh_public_key_pending')) {
            return;
        }

        $this->addSql('ALTER TABLE users ADD admin_ssh_public_key_pending LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }

        $table = $schema->getTable('users');
        if (!$table->hasColumn('admin_ssh_public_key_pending')) {
            return;
        }

        $this->addSql('ALTER TABLE users DROP admin_ssh_public_key_pending');
    }
}
