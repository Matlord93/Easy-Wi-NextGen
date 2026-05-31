<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260117114000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Steam account, GSLT key, and server name to instances.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instances ADD steam_account VARCHAR(255) DEFAULT NULL, ADD gsl_key VARCHAR(255) DEFAULT NULL, ADD server_name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instances DROP steam_account, DROP gsl_key, DROP server_name');
    }
}
