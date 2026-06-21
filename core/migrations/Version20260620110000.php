<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260620110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Musicbot runtime event log table.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('musicbot_runtime_events')) {
            return;
        }

        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->addSql('CREATE TABLE musicbot_runtime_events (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, musicbot_instance_id INTEGER NOT NULL, customer_id INTEGER NOT NULL, type VARCHAR(80) NOT NULL, level VARCHAR(16) NOT NULL, message VARCHAR(255) NOT NULL, context CLOB NOT NULL, created_at DATETIME NOT NULL, FOREIGN KEY(musicbot_instance_id) REFERENCES musicbot_instances (id) ON DELETE CASCADE, FOREIGN KEY(customer_id) REFERENCES users (id) ON DELETE CASCADE)');
            $this->addSql('CREATE INDEX idx_musicbot_runtime_events_instance_created ON musicbot_runtime_events (musicbot_instance_id, created_at)');
            $this->addSql('CREATE INDEX idx_musicbot_runtime_events_type ON musicbot_runtime_events (type)');
            $this->addSql('CREATE INDEX idx_musicbot_runtime_events_customer ON musicbot_runtime_events (customer_id)');
            return;
        }

        $this->addSql('CREATE TABLE musicbot_runtime_events (id INT AUTO_INCREMENT NOT NULL, musicbot_instance_id INT NOT NULL, customer_id INT NOT NULL, type VARCHAR(80) NOT NULL, level VARCHAR(16) NOT NULL, message VARCHAR(255) NOT NULL, context JSON NOT NULL, created_at DATETIME NOT NULL, INDEX idx_musicbot_runtime_events_instance_created (musicbot_instance_id, created_at), INDEX idx_musicbot_runtime_events_type (type), INDEX idx_musicbot_runtime_events_customer (customer_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE musicbot_runtime_events ADD CONSTRAINT FK_MUSICBOT_RUNTIME_EVENTS_INSTANCE FOREIGN KEY (musicbot_instance_id) REFERENCES musicbot_instances (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE musicbot_runtime_events ADD CONSTRAINT FK_MUSICBOT_RUNTIME_EVENTS_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS musicbot_runtime_events');
    }
}
