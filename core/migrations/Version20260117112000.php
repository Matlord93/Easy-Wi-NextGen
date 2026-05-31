<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260117112000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add service API settings to agents.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agents ADD service_base_url VARCHAR(255) DEFAULT NULL, ADD service_api_token_encrypted LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agents DROP service_base_url, DROP service_api_token_encrypted');
    }
}
