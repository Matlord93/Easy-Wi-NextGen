<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20250307120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add instance base directory column for multi-disk installs.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('instances')) {
            return;
        }

        $table = $schema->getTable('instances');
        if ($table->hasColumn('instance_base_dir')) {
            return;
        }

        $this->addSql('ALTER TABLE instances ADD instance_base_dir VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('instances')) {
            return;
        }

        $table = $schema->getTable('instances');
        if (!$table->hasColumn('instance_base_dir')) {
            return;
        }

        $this->addSql('ALTER TABLE instances DROP instance_base_dir');
    }
}
