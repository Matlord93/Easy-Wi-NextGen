<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260323110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Raise agent job concurrency defaults to 50.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('agents')) {
            return;
        }

        $table = $schema->getTable('agents');
        if (!$table->hasColumn('job_concurrency')) {
            return;
        }

        $this->addSql('ALTER TABLE agents ALTER job_concurrency SET DEFAULT 50');
        $this->addSql('UPDATE agents SET job_concurrency = 50 WHERE job_concurrency < 50');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('agents')) {
            return;
        }

        $table = $schema->getTable('agents');
        if (!$table->hasColumn('job_concurrency')) {
            return;
        }

        $this->addSql('ALTER TABLE agents ALTER job_concurrency SET DEFAULT 1');
    }
}
