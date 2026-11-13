<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113030000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Store hourly sufficient statistics for bounded-memory long-term VM monitoring.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $table = $schema->createTable('infrastructure_rollups');
        $table->addColumn('resource_key', 'string', ['length' => 64]);
        $table->addColumn('stream', 'string', ['length' => 4]);
        $table->addColumn('bucket', 'bigint');
        $table->addColumn('payload', 'json');
        $table->setPrimaryKey(['resource_key', 'stream', 'bucket']);
        $table->addIndex(['bucket'], 'idx_infra_rollup_retention');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve monitoring archives.');
    }
}
