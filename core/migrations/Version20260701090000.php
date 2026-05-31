<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260701090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add public host override for TeamSpeak virtual servers.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('ts3_virtual_servers') && !$schema->getTable('ts3_virtual_servers')->hasColumn('public_host')) {
            $this->addSql('ALTER TABLE ts3_virtual_servers ADD public_host VARCHAR(190) DEFAULT NULL');
        }

        if ($schema->hasTable('ts6_virtual_servers') && !$schema->getTable('ts6_virtual_servers')->hasColumn('public_host')) {
            $this->addSql('ALTER TABLE ts6_virtual_servers ADD public_host VARCHAR(190) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('ts3_virtual_servers') && $schema->getTable('ts3_virtual_servers')->hasColumn('public_host')) {
            $this->addSql('ALTER TABLE ts3_virtual_servers DROP public_host');
        }

        if ($schema->hasTable('ts6_virtual_servers') && $schema->getTable('ts6_virtual_servers')->hasColumn('public_host')) {
            $this->addSql('ALTER TABLE ts6_virtual_servers DROP public_host');
        }
    }
}
