<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260620160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create musicbot_autodj_settings table';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $isSqlite = $platform instanceof \Doctrine\DBAL\Platforms\SqlitePlatform;

        if ($isSqlite) {
            $this->addSql('CREATE TABLE musicbot_autodj_settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                customer_id INTEGER NOT NULL,
                instance_id INTEGER NOT NULL,
                enabled BOOLEAN NOT NULL,
                fallback_playlist_id INTEGER DEFAULT NULL,
                mode VARCHAR(30) NOT NULL,
                avoid_repeats BOOLEAN NOT NULL,
                min_queue_size INTEGER NOT NULL,
                genre_filter VARCHAR(120) DEFAULT NULL,
                last_played_track_ids CLOB NOT NULL DEFAULT \'[]\',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                CONSTRAINT fk_autodj_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_autodj_instance FOREIGN KEY (instance_id) REFERENCES musicbot_instances (id) ON DELETE CASCADE,
                CONSTRAINT fk_autodj_playlist FOREIGN KEY (fallback_playlist_id) REFERENCES musicbot_playlists (id) ON DELETE SET NULL
            )');
            $this->addSql('CREATE UNIQUE INDEX uniq_musicbot_autodj_instance ON musicbot_autodj_settings (instance_id)');
            $this->addSql('CREATE INDEX idx_musicbot_autodj_customer ON musicbot_autodj_settings (customer_id)');
        } else {
            $this->addSql('CREATE TABLE musicbot_autodj_settings (
                id INT AUTO_INCREMENT NOT NULL,
                customer_id INT NOT NULL,
                instance_id INT NOT NULL,
                enabled TINYINT(1) NOT NULL,
                fallback_playlist_id INT DEFAULT NULL,
                mode VARCHAR(30) NOT NULL,
                avoid_repeats TINYINT(1) NOT NULL,
                min_queue_size INT NOT NULL,
                genre_filter VARCHAR(120) DEFAULT NULL,
                last_played_track_ids JSON NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_musicbot_autodj_instance (instance_id),
                INDEX idx_musicbot_autodj_customer (customer_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE musicbot_autodj_settings ADD CONSTRAINT fk_autodj_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE musicbot_autodj_settings ADD CONSTRAINT fk_autodj_instance FOREIGN KEY (instance_id) REFERENCES musicbot_instances (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE musicbot_autodj_settings ADD CONSTRAINT fk_autodj_playlist FOREIGN KEY (fallback_playlist_id) REFERENCES musicbot_playlists (id) ON DELETE SET NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $isSqlite = $platform instanceof \Doctrine\DBAL\Platforms\SqlitePlatform;

        if (!$isSqlite) {
            $this->addSql('ALTER TABLE musicbot_autodj_settings DROP FOREIGN KEY fk_autodj_customer');
            $this->addSql('ALTER TABLE musicbot_autodj_settings DROP FOREIGN KEY fk_autodj_instance');
            $this->addSql('ALTER TABLE musicbot_autodj_settings DROP FOREIGN KEY fk_autodj_playlist');
        }

        $this->addSql('DROP TABLE IF EXISTS musicbot_autodj_settings');
    }
}
