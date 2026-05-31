<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260117115000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add slots and assigned port to instances.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instances ADD slots INT NOT NULL DEFAULT 16, ADD assigned_port INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instances DROP slots, DROP assigned_port');
    }
}
