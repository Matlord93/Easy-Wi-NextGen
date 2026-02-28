<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add strict security event deduplication and retention metadata.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('security_events')) {
            return;
        }

        $table = $schema->getTable('security_events');
        if (!$table->hasColumn('dedup_key')) {
            $this->addSql("ALTER TABLE security_events ADD dedup_key VARCHAR(64) DEFAULT ''");
        }
        if (!$table->hasColumn('expires_at')) {
            $this->addSql('ALTER TABLE security_events ADD expires_at DATETIME DEFAULT NULL');
        }

        $this->addSql("UPDATE security_events SET dedup_key = SHA2(CONCAT(COALESCE(node_id, ''), '|', direction, '|', source, '|', COALESCE(ip, ''), '|', COALESCE(rule, ''), '|', DATE_FORMAT(occurred_at, '%Y-%m-%dT%H:%i:%sZ')), 256) WHERE dedup_key = '' OR dedup_key IS NULL");
        $this->addSql('UPDATE security_events SET expires_at = DATE_ADD(created_at, INTERVAL 7 DAY) WHERE expires_at IS NULL');
        $this->addSql('ALTER TABLE security_events MODIFY dedup_key VARCHAR(64) NOT NULL');
        $this->addSql('ALTER TABLE security_events MODIFY expires_at DATETIME NOT NULL');

        $this->addSql('CREATE INDEX idx_security_events_dedup ON security_events (dedup_key)');
        $this->addSql('CREATE INDEX idx_security_events_expires ON security_events (expires_at)');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('security_events')) {
            return;
        }

        $table = $schema->getTable('security_events');
        if ($table->hasIndex('idx_security_events_expires')) {
            $this->addSql('DROP INDEX idx_security_events_expires ON security_events');
        }
        if ($table->hasIndex('idx_security_events_dedup')) {
            $this->addSql('DROP INDEX idx_security_events_dedup ON security_events');
        }
        if ($table->hasColumn('expires_at')) {
            $this->addSql('ALTER TABLE security_events DROP expires_at');
        }
        if ($table->hasColumn('dedup_key')) {
            $this->addSql('ALTER TABLE security_events DROP dedup_key');
        }
    }
}
