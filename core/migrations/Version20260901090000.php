<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260901090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add member/customer access flags for role bridge.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }

        $table = $schema->getTable('users');

        if (!$table->hasColumn('member_access_enabled')) {
            $this->addSql('ALTER TABLE users ADD member_access_enabled TINYINT(1) NOT NULL DEFAULT 0');
        }

        if (!$table->hasColumn('customer_access_enabled')) {
            $this->addSql('ALTER TABLE users ADD customer_access_enabled TINYINT(1) NOT NULL DEFAULT 0');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }

        $table = $schema->getTable('users');

        if ($table->hasColumn('customer_access_enabled')) {
            $this->addSql('ALTER TABLE users DROP customer_access_enabled');
        }

        if ($table->hasColumn('member_access_enabled')) {
            $this->addSql('ALTER TABLE users DROP member_access_enabled');
        }
    }
}
