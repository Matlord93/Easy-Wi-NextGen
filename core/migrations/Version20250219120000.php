<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20250219120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add portal language preference to invoice preferences.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE invoice_preferences ADD portal_language VARCHAR(5) NOT NULL DEFAULT 'de'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_preferences DROP portal_language');
    }
}
