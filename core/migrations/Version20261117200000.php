<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117200000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Persist encrypted generic backup plans, expiring idempotency records and worker leases.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $plans = $schema->createTable('backup_plans');
        $plans->addColumn('record_key', 'string', ['length' => 64]);
        $plans->addColumn('payload', 'text', ['length' => 16777215]);
        $plans->setPrimaryKey(['record_key']);
        $runs = $schema->createTable('backup_plan_runs');
        $runs->addColumn('record_key', 'string', ['length' => 64]);
        $runs->addColumn('payload', 'text', ['length' => 16777215]);
        $runs->addColumn('expires_at', 'bigint');
        $runs->setPrimaryKey(['record_key']);
        $runs->addIndex(['expires_at'], 'idx_backup_run_expiry');
        $locks = $schema->createTable('backup_plan_locks');
        $locks->addColumn('record_key', 'string', ['length' => 64]);
        $locks->addColumn('owner_token', 'string', ['length' => 48]);
        $locks->addColumn('expires_at', 'bigint');
        $locks->setPrimaryKey(['record_key']);
        $locks->addIndex(['expires_at'], 'idx_backup_lock_expiry');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve encrypted backup plans and run records.');
    }
}
