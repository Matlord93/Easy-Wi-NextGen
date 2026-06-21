<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260620170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create musicbot_stream_settings table';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $isSqlite = $platform instanceof \Doctrine\DBAL\Platforms\SqlitePlatform;

        if ($isSqlite) {
            $this->addSql('CREATE TABLE musicbot_stream_settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                customer_id INTEGER NOT NULL,
                instance_id INTEGER NOT NULL,
                enabled BOOLEAN NOT NULL DEFAULT 0,
                public_slug VARCHAR(64) NOT NULL,
                access_mode VARCHAR(10) NOT NULL DEFAULT \'private\',
                stream_title VARCHAR(150) DEFAULT NULL,
                bitrate INTEGER NOT NULL DEFAULT 128,
                format VARCHAR(10) NOT NULL DEFAULT \'mp3\',
                current_mount_path VARCHAR(120) DEFAULT NULL,
                stream_token_hash VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                CONSTRAINT fk_stream_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_stream_instance FOREIGN KEY (instance_id) REFERENCES musicbot_instances (id) ON DELETE CASCADE
            )');
            $this->addSql('CREATE UNIQUE INDEX uniq_musicbot_stream_instance ON musicbot_stream_settings (instance_id)');
            $this->addSql('CREATE UNIQUE INDEX uniq_musicbot_stream_slug ON musicbot_stream_settings (public_slug)');
            $this->addSql('CREATE INDEX idx_musicbot_stream_customer ON musicbot_stream_settings (customer_id)');
        } else {
            $this->addSql('CREATE TABLE musicbot_stream_settings (
                id INT AUTO_INCREMENT NOT NULL,
                customer_id INT NOT NULL,
                instance_id INT NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                public_slug VARCHAR(64) NOT NULL,
                access_mode VARCHAR(10) NOT NULL DEFAULT \'private\',
                stream_title VARCHAR(150) DEFAULT NULL,
                bitrate INT NOT NULL DEFAULT 128,
                format VARCHAR(10) NOT NULL DEFAULT \'mp3\',
                current_mount_path VARCHAR(120) DEFAULT NULL,
                stream_token_hash VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX uniq_musicbot_stream_instance (instance_id),
                UNIQUE INDEX uniq_musicbot_stream_slug (public_slug),
                INDEX idx_musicbot_stream_customer (customer_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE musicbot_stream_settings ADD CONSTRAINT fk_stream_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE musicbot_stream_settings ADD CONSTRAINT fk_stream_instance FOREIGN KEY (instance_id) REFERENCES musicbot_instances (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $isSqlite = $platform instanceof \Doctrine\DBAL\Platforms\SqlitePlatform;

        if (!$isSqlite) {
            $this->addSql('ALTER TABLE musicbot_stream_settings DROP FOREIGN KEY fk_stream_customer');
            $this->addSql('ALTER TABLE musicbot_stream_settings DROP FOREIGN KEY fk_stream_instance');
        }

        $this->addSql('DROP TABLE IF EXISTS musicbot_stream_settings');
    }
}
