<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117190000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Durable, versioned UniFi port synchronization requests independent of Messenger transport.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $table = $schema->createTable('unifi_sync_requests');
        $table->addColumn('instance_id', 'integer');
        $table->addColumn('revision', 'integer', ['default' => 1]);
        $table->addColumn('requested_at', 'datetime_immutable');
        $table->addColumn('available_at', 'datetime_immutable');
        $table->addColumn('claimed_until', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('claim_token', 'string', ['length' => 64, 'notnull' => false]);
        $table->addColumn('attempts', 'integer', ['default' => 0]);
        $table->addColumn('last_error', 'text', ['notnull' => false]);
        $table->setPrimaryKey(['instance_id']);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Pending port synchronizations must not be discarded.');
    }
}
