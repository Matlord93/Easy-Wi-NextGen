<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20250315100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add query cache fields for instances.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instances ADD query_status_cache JSON NOT NULL DEFAULT (JSON_OBJECT()), ADD query_checked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instances DROP query_status_cache, DROP query_checked_at');
    }
}
