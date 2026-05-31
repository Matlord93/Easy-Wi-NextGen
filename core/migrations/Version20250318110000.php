<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20250318110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store domain server aliases for subdomain management.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('domains')) {
            return;
        }

        $table = $schema->getTable('domains');
        if ($table->hasColumn('server_aliases')) {
            return;
        }

        $this->addSql('ALTER TABLE domains ADD server_aliases LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('domains')) {
            return;
        }

        $table = $schema->getTable('domains');
        if (!$table->hasColumn('server_aliases')) {
            return;
        }

        $this->addSql('ALTER TABLE domains DROP server_aliases');
    }
}
