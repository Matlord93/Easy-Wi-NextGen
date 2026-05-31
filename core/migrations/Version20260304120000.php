<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260304120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add default filetransfer port to TS3 nodes and default voice port to TS6 nodes.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('ts3_nodes')) {
            $table = $schema->getTable('ts3_nodes');
            if (!$table->hasColumn('filetransfer_port')) {
                $this->addSql('ALTER TABLE ts3_nodes ADD filetransfer_port INT NOT NULL DEFAULT 30033');
            }
        }

        if ($schema->hasTable('ts6_nodes')) {
            $table = $schema->getTable('ts6_nodes');
            if (!$table->hasColumn('voice_port')) {
                $this->addSql('ALTER TABLE ts6_nodes ADD voice_port INT NOT NULL DEFAULT 9987');
            }
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('ts3_nodes')) {
            $table = $schema->getTable('ts3_nodes');
            if ($table->hasColumn('filetransfer_port')) {
                $this->addSql('ALTER TABLE ts3_nodes DROP COLUMN filetransfer_port');
            }
        }

        if ($schema->hasTable('ts6_nodes')) {
            $table = $schema->getTable('ts6_nodes');
            if ($table->hasColumn('voice_port')) {
                $this->addSql('ALTER TABLE ts6_nodes DROP COLUMN voice_port');
            }
        }
    }
}
