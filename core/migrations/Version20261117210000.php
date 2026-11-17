<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117210000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Keep an encrypted archive inventory independently of expiring backup idempotency records.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $table = $schema->createTable('backup_archive_inventory');
        $table->addColumn('record_key', 'string', ['length' => 64]);
        $table->addColumn('plan_key', 'string', ['length' => 64]);
        $table->addColumn('archive_key', 'string', ['length' => 64]);
        $table->addColumn('payload', 'text', ['length' => 16777215]);
        $table->setPrimaryKey(['record_key']);
        $table->addIndex(['plan_key'], 'idx_backup_archive_plan');
        $table->addIndex(['archive_key'], 'idx_backup_archive_path');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve archive provenance and retention inventory.');
    }
}
