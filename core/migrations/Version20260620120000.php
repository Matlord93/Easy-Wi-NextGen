<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260620120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add musicbot_customer_limits table for per-customer quota and permission overrides.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('musicbot_customer_limits')) {
            return;
        }

        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->addSql('CREATE TABLE musicbot_customer_limits (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                customer_id INTEGER NOT NULL,
                max_musicbots INTEGER DEFAULT NULL,
                max_tracks INTEGER DEFAULT NULL,
                max_storage_mb INTEGER DEFAULT NULL,
                max_playlists INTEGER DEFAULT NULL,
                max_plugins INTEGER DEFAULT NULL,
                max_queue_items INTEGER DEFAULT NULL,
                max_connections INTEGER DEFAULT NULL,
                max_upload_size_mb INTEGER DEFAULT NULL,
                allow_teamspeak BOOLEAN DEFAULT NULL,
                allow_discord BOOLEAN DEFAULT NULL,
                allow_teamspeak6_profile BOOLEAN DEFAULT NULL,
                allow_webradio BOOLEAN DEFAULT NULL,
                allow_plugins BOOLEAN DEFAULT NULL,
                allow_workflows BOOLEAN DEFAULT NULL,
                allow_scheduler BOOLEAN DEFAULT NULL,
                granted_permissions CLOB DEFAULT NULL,
                updated_at DATETIME NOT NULL,
                FOREIGN KEY(customer_id) REFERENCES users (id) ON DELETE CASCADE
            )');
            $this->addSql('CREATE UNIQUE INDEX uniq_musicbot_customer_limits_customer ON musicbot_customer_limits (customer_id)');
            $this->addSql('CREATE INDEX idx_musicbot_customer_limits_customer ON musicbot_customer_limits (customer_id)');

            return;
        }

        $this->addSql('CREATE TABLE musicbot_customer_limits (
            id INT AUTO_INCREMENT NOT NULL,
            customer_id INT NOT NULL,
            max_musicbots INT DEFAULT NULL,
            max_tracks INT DEFAULT NULL,
            max_storage_mb INT DEFAULT NULL,
            max_playlists INT DEFAULT NULL,
            max_plugins INT DEFAULT NULL,
            max_queue_items INT DEFAULT NULL,
            max_connections INT DEFAULT NULL,
            max_upload_size_mb INT DEFAULT NULL,
            allow_teamspeak TINYINT(1) DEFAULT NULL,
            allow_discord TINYINT(1) DEFAULT NULL,
            allow_teamspeak6_profile TINYINT(1) DEFAULT NULL,
            allow_webradio TINYINT(1) DEFAULT NULL,
            allow_plugins TINYINT(1) DEFAULT NULL,
            allow_workflows TINYINT(1) DEFAULT NULL,
            allow_scheduler TINYINT(1) DEFAULT NULL,
            granted_permissions JSON DEFAULT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_musicbot_customer_limits_customer (customer_id),
            INDEX idx_musicbot_customer_limits_customer (customer_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE musicbot_customer_limits ADD CONSTRAINT FK_MUSICBOT_CUSTOMER_LIMITS_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS musicbot_customer_limits');
    }
}
