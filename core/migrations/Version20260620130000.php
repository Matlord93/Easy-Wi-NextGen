<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260620130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add musicbot_schedules table for customer-defined cron-based musicbot actions.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('musicbot_schedules')) {
            return;
        }

        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->addSql('CREATE TABLE musicbot_schedules (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                customer_id INTEGER NOT NULL,
                instance_id INTEGER NOT NULL,
                name VARCHAR(120) NOT NULL,
                cron_expression VARCHAR(120) NOT NULL,
                timezone VARCHAR(64) DEFAULT NULL,
                enabled BOOLEAN NOT NULL,
                action VARCHAR(32) NOT NULL,
                payload CLOB DEFAULT NULL,
                last_run_at DATETIME DEFAULT NULL,
                next_run_at DATETIME DEFAULT NULL,
                last_error CLOB DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                FOREIGN KEY(customer_id) REFERENCES users (id) ON DELETE CASCADE,
                FOREIGN KEY(instance_id) REFERENCES musicbot_instances (id) ON DELETE CASCADE
            )');
            $this->addSql('CREATE INDEX idx_musicbot_schedules_customer ON musicbot_schedules (customer_id)');
            $this->addSql('CREATE INDEX idx_musicbot_schedules_instance ON musicbot_schedules (instance_id)');
            $this->addSql('CREATE INDEX idx_musicbot_schedules_next_run ON musicbot_schedules (enabled, next_run_at)');

            return;
        }

        $this->addSql('CREATE TABLE musicbot_schedules (
            id INT AUTO_INCREMENT NOT NULL,
            customer_id INT NOT NULL,
            instance_id INT NOT NULL,
            name VARCHAR(120) NOT NULL,
            cron_expression VARCHAR(120) NOT NULL,
            timezone VARCHAR(64) DEFAULT NULL,
            enabled TINYINT(1) NOT NULL,
            action VARCHAR(32) NOT NULL,
            payload JSON DEFAULT NULL,
            last_run_at DATETIME DEFAULT NULL,
            next_run_at DATETIME DEFAULT NULL,
            last_error LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_musicbot_schedules_customer (customer_id),
            INDEX idx_musicbot_schedules_instance (instance_id),
            INDEX idx_musicbot_schedules_next_run (enabled, next_run_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE musicbot_schedules ADD CONSTRAINT FK_MUSICBOT_SCHEDULES_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE musicbot_schedules ADD CONSTRAINT FK_MUSICBOT_SCHEDULES_INSTANCE FOREIGN KEY (instance_id) REFERENCES musicbot_instances (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS musicbot_schedules');
    }
}
