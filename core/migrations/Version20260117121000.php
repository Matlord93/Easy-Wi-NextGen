<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260117121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add domain, DDoS protection flag, and assigned port to webspaces.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('webspaces')) {
            return;
        }

        $this->addSql("ALTER TABLE webspaces ADD domain VARCHAR(255) NOT NULL DEFAULT ''");
        $this->addSql('ALTER TABLE webspaces ADD ddos_protection_enabled TINYINT(1) NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE webspaces ADD assigned_port INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('webspaces')) {
            return;
        }

        $this->addSql('ALTER TABLE webspaces DROP domain');
        $this->addSql('ALTER TABLE webspaces DROP ddos_protection_enabled');
        $this->addSql('ALTER TABLE webspaces DROP assigned_port');
    }
}
