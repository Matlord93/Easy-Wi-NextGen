<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260701100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist gameserver config overrides on instances.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('instances')) {
            return;
        }

        $table = $schema->getTable('instances');
        if ($table->hasColumn('config_overrides')) {
            return;
        }

        $this->addSql('ALTER TABLE instances ADD config_overrides JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('instances')) {
            return;
        }

        $table = $schema->getTable('instances');
        if (!$table->hasColumn('config_overrides')) {
            return;
        }

        $this->addSql('ALTER TABLE instances DROP config_overrides');
    }
}
