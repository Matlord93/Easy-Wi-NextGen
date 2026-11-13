<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist atomic Agent API replay protection and trusted per-node SFTP host fingerprints.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('agent_request_nonces');
        $table->addColumn('nonce_key', 'string', ['length' => 64]);
        $table->addColumn('expires_at', 'bigint');
        $table->setPrimaryKey(['nonce_key']);
        $table->addIndex(['expires_at'], 'idx_agent_request_nonces_expiry');
        $schema->getTable('agents')->addColumn('sftp_host_fingerprint', 'string', ['length' => 80, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Replay history and trusted host identities must be preserved.');
    }
}
