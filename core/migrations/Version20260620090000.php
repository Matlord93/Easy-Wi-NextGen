<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260620090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add native Musicbot domain tables.';
    }

    public function up(Schema $schema): void
    {
        $isSqlite = $this->connection->getDatabasePlatform() instanceof SQLitePlatform;
        if ($isSqlite) {
            $this->upSqlite($schema);
            return;
        }

        $json = 'JSON';

        if (!$schema->hasTable('musicbot_instances')) {
            $this->addSql("CREATE TABLE musicbot_instances (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, node_id VARCHAR(64) NOT NULL, name VARCHAR(120) NOT NULL, status VARCHAR(255) NOT NULL, service_name VARCHAR(120) NOT NULL, install_path VARCHAR(255) NOT NULL, cpu_limit INT NOT NULL, ram_limit INT NOT NULL, disk_limit INT NOT NULL, last_error LONGTEXT DEFAULT NULL, runtime_payload JSON DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_musicbot_instances_customer (customer_id), INDEX idx_musicbot_instances_node (node_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
            $this->addSql('ALTER TABLE musicbot_instances ADD CONSTRAINT FK_MUSICBOT_INSTANCES_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE musicbot_instances ADD CONSTRAINT FK_MUSICBOT_INSTANCES_NODE FOREIGN KEY (node_id) REFERENCES agents (id) ON DELETE CASCADE');
        }

        if (!$schema->hasTable('musicbot_connections')) {
            $this->addSql("CREATE TABLE musicbot_connections (id INT AUTO_INCREMENT NOT NULL, musicbot_instance_id INT NOT NULL, platform VARCHAR(255) NOT NULL, enabled TINYINT(1) DEFAULT 1 NOT NULL, connection_config {$json} NOT NULL, secret_config {$json} NOT NULL, status VARCHAR(255) NOT NULL, last_connected_at DATETIME DEFAULT NULL, last_error LONGTEXT DEFAULT NULL, INDEX idx_musicbot_connections_instance (musicbot_instance_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
            $this->addSql('ALTER TABLE musicbot_connections ADD CONSTRAINT FK_MUSICBOT_CONNECTIONS_INSTANCE FOREIGN KEY (musicbot_instance_id) REFERENCES musicbot_instances (id) ON DELETE CASCADE');
        }

        if (!$schema->hasTable('musicbot_tracks')) {
            $this->addSql("CREATE TABLE musicbot_tracks (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, instance_id INT DEFAULT NULL, title VARCHAR(190) NOT NULL, artist VARCHAR(190) DEFAULT NULL, duration_seconds INT NOT NULL, source_type VARCHAR(255) NOT NULL, file_path VARCHAR(255) DEFAULT NULL, mime_type VARCHAR(120) NOT NULL, sha256 VARCHAR(64) NOT NULL, metadata {$json} NOT NULL, created_at DATETIME NOT NULL, INDEX idx_musicbot_tracks_customer (customer_id), INDEX IDX_MUSICBOT_TRACKS_INSTANCE (instance_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
            $this->addSql('ALTER TABLE musicbot_tracks ADD CONSTRAINT FK_MUSICBOT_TRACKS_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE musicbot_tracks ADD CONSTRAINT FK_MUSICBOT_TRACKS_INSTANCE FOREIGN KEY (instance_id) REFERENCES musicbot_instances (id) ON DELETE SET NULL');
        }

        if (!$schema->hasTable('musicbot_queue_items')) {
            $this->addSql("CREATE TABLE musicbot_queue_items (id INT AUTO_INCREMENT NOT NULL, instance_id INT NOT NULL, track_id INT NOT NULL, requested_by_id INT DEFAULT NULL, position INT NOT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, INDEX idx_musicbot_queue_instance_position (instance_id, position), INDEX IDX_MUSICBOT_QUEUE_TRACK (track_id), INDEX IDX_MUSICBOT_QUEUE_REQUESTED_BY (requested_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
            $this->addSql('ALTER TABLE musicbot_queue_items ADD CONSTRAINT FK_MUSICBOT_QUEUE_INSTANCE FOREIGN KEY (instance_id) REFERENCES musicbot_instances (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE musicbot_queue_items ADD CONSTRAINT FK_MUSICBOT_QUEUE_TRACK FOREIGN KEY (track_id) REFERENCES musicbot_tracks (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE musicbot_queue_items ADD CONSTRAINT FK_MUSICBOT_QUEUE_REQUESTED_BY FOREIGN KEY (requested_by_id) REFERENCES users (id) ON DELETE SET NULL');
        }

        if (!$schema->hasTable('musicbot_playlists')) {
            $this->addSql("CREATE TABLE musicbot_playlists (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, instance_id INT DEFAULT NULL, name VARCHAR(120) NOT NULL, visibility VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_musicbot_playlists_customer (customer_id), INDEX IDX_MUSICBOT_PLAYLISTS_INSTANCE (instance_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
            $this->addSql('ALTER TABLE musicbot_playlists ADD CONSTRAINT FK_MUSICBOT_PLAYLISTS_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE musicbot_playlists ADD CONSTRAINT FK_MUSICBOT_PLAYLISTS_INSTANCE FOREIGN KEY (instance_id) REFERENCES musicbot_instances (id) ON DELETE SET NULL');
        }

        if (!$schema->hasTable('musicbot_playlist_items')) {
            $this->addSql("CREATE TABLE musicbot_playlist_items (id INT AUTO_INCREMENT NOT NULL, playlist_id INT NOT NULL, track_id INT NOT NULL, position INT NOT NULL, INDEX idx_musicbot_playlist_items_playlist_position (playlist_id, position), INDEX IDX_MUSICBOT_PLAYLIST_ITEMS_TRACK (track_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
            $this->addSql('ALTER TABLE musicbot_playlist_items ADD CONSTRAINT FK_MUSICBOT_PLAYLIST_ITEMS_PLAYLIST FOREIGN KEY (playlist_id) REFERENCES musicbot_playlists (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE musicbot_playlist_items ADD CONSTRAINT FK_MUSICBOT_PLAYLIST_ITEMS_TRACK FOREIGN KEY (track_id) REFERENCES musicbot_tracks (id) ON DELETE CASCADE');
        }

        if (!$schema->hasTable('musicbot_plugins')) {
            $this->addSql("CREATE TABLE musicbot_plugins (id INT AUTO_INCREMENT NOT NULL, customer_id INT DEFAULT NULL, instance_id INT DEFAULT NULL, identifier VARCHAR(120) NOT NULL, name VARCHAR(120) NOT NULL, version VARCHAR(40) NOT NULL, enabled TINYINT(1) DEFAULT 0 NOT NULL, config {$json} NOT NULL, permissions {$json} NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_musicbot_plugins_customer (customer_id), INDEX idx_musicbot_plugins_instance (instance_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
            $this->addSql('ALTER TABLE musicbot_plugins ADD CONSTRAINT FK_MUSICBOT_PLUGINS_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE musicbot_plugins ADD CONSTRAINT FK_MUSICBOT_PLUGINS_INSTANCE FOREIGN KEY (instance_id) REFERENCES musicbot_instances (id) ON DELETE CASCADE');
        }
    }

    private function upSqlite(Schema $schema): void
    {
        if (!$schema->hasTable('musicbot_instances')) {
            $this->addSql('CREATE TABLE musicbot_instances (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, customer_id INTEGER NOT NULL, node_id VARCHAR(64) NOT NULL, name VARCHAR(120) NOT NULL, status VARCHAR(255) NOT NULL, service_name VARCHAR(120) NOT NULL, install_path VARCHAR(255) NOT NULL, cpu_limit INTEGER NOT NULL, ram_limit INTEGER NOT NULL, disk_limit INTEGER NOT NULL, last_error CLOB DEFAULT NULL, runtime_payload CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, FOREIGN KEY(customer_id) REFERENCES users (id) ON DELETE CASCADE, FOREIGN KEY(node_id) REFERENCES agents (id) ON DELETE CASCADE)');
            $this->addSql('CREATE INDEX idx_musicbot_instances_customer ON musicbot_instances (customer_id)');
            $this->addSql('CREATE INDEX idx_musicbot_instances_node ON musicbot_instances (node_id)');
        }
        if (!$schema->hasTable('musicbot_connections')) {
            $this->addSql('CREATE TABLE musicbot_connections (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, musicbot_instance_id INTEGER NOT NULL, platform VARCHAR(255) NOT NULL, enabled BOOLEAN DEFAULT 1 NOT NULL, connection_config CLOB NOT NULL, secret_config CLOB NOT NULL, status VARCHAR(255) NOT NULL, last_connected_at DATETIME DEFAULT NULL, last_error CLOB DEFAULT NULL, FOREIGN KEY(musicbot_instance_id) REFERENCES musicbot_instances (id) ON DELETE CASCADE)');
            $this->addSql('CREATE INDEX idx_musicbot_connections_instance ON musicbot_connections (musicbot_instance_id)');
        }
        if (!$schema->hasTable('musicbot_tracks')) {
            $this->addSql('CREATE TABLE musicbot_tracks (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, customer_id INTEGER NOT NULL, instance_id INTEGER DEFAULT NULL, title VARCHAR(190) NOT NULL, artist VARCHAR(190) DEFAULT NULL, duration_seconds INTEGER NOT NULL, source_type VARCHAR(255) NOT NULL, file_path VARCHAR(255) DEFAULT NULL, mime_type VARCHAR(120) NOT NULL, sha256 VARCHAR(64) NOT NULL, metadata CLOB NOT NULL, created_at DATETIME NOT NULL, FOREIGN KEY(customer_id) REFERENCES users (id) ON DELETE CASCADE, FOREIGN KEY(instance_id) REFERENCES musicbot_instances (id) ON DELETE SET NULL)');
            $this->addSql('CREATE INDEX idx_musicbot_tracks_customer ON musicbot_tracks (customer_id)');
            $this->addSql('CREATE INDEX IDX_MUSICBOT_TRACKS_INSTANCE ON musicbot_tracks (instance_id)');
        }
        if (!$schema->hasTable('musicbot_queue_items')) {
            $this->addSql('CREATE TABLE musicbot_queue_items (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, instance_id INTEGER NOT NULL, track_id INTEGER NOT NULL, requested_by_id INTEGER DEFAULT NULL, position INTEGER NOT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, FOREIGN KEY(instance_id) REFERENCES musicbot_instances (id) ON DELETE CASCADE, FOREIGN KEY(track_id) REFERENCES musicbot_tracks (id) ON DELETE CASCADE, FOREIGN KEY(requested_by_id) REFERENCES users (id) ON DELETE SET NULL)');
            $this->addSql('CREATE INDEX idx_musicbot_queue_instance_position ON musicbot_queue_items (instance_id, position)');
            $this->addSql('CREATE INDEX IDX_MUSICBOT_QUEUE_TRACK ON musicbot_queue_items (track_id)');
            $this->addSql('CREATE INDEX IDX_MUSICBOT_QUEUE_REQUESTED_BY ON musicbot_queue_items (requested_by_id)');
        }
        if (!$schema->hasTable('musicbot_playlists')) {
            $this->addSql('CREATE TABLE musicbot_playlists (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, customer_id INTEGER NOT NULL, instance_id INTEGER DEFAULT NULL, name VARCHAR(120) NOT NULL, visibility VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, FOREIGN KEY(customer_id) REFERENCES users (id) ON DELETE CASCADE, FOREIGN KEY(instance_id) REFERENCES musicbot_instances (id) ON DELETE SET NULL)');
            $this->addSql('CREATE INDEX idx_musicbot_playlists_customer ON musicbot_playlists (customer_id)');
            $this->addSql('CREATE INDEX IDX_MUSICBOT_PLAYLISTS_INSTANCE ON musicbot_playlists (instance_id)');
        }
        if (!$schema->hasTable('musicbot_playlist_items')) {
            $this->addSql('CREATE TABLE musicbot_playlist_items (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, playlist_id INTEGER NOT NULL, track_id INTEGER NOT NULL, position INTEGER NOT NULL, FOREIGN KEY(playlist_id) REFERENCES musicbot_playlists (id) ON DELETE CASCADE, FOREIGN KEY(track_id) REFERENCES musicbot_tracks (id) ON DELETE CASCADE)');
            $this->addSql('CREATE INDEX idx_musicbot_playlist_items_playlist_position ON musicbot_playlist_items (playlist_id, position)');
            $this->addSql('CREATE INDEX IDX_MUSICBOT_PLAYLIST_ITEMS_TRACK ON musicbot_playlist_items (track_id)');
        }
        if (!$schema->hasTable('musicbot_plugins')) {
            $this->addSql('CREATE TABLE musicbot_plugins (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, customer_id INTEGER DEFAULT NULL, instance_id INTEGER DEFAULT NULL, identifier VARCHAR(120) NOT NULL, name VARCHAR(120) NOT NULL, version VARCHAR(40) NOT NULL, enabled BOOLEAN DEFAULT 0 NOT NULL, config CLOB NOT NULL, permissions CLOB NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, FOREIGN KEY(customer_id) REFERENCES users (id) ON DELETE CASCADE, FOREIGN KEY(instance_id) REFERENCES musicbot_instances (id) ON DELETE CASCADE)');
            $this->addSql('CREATE INDEX idx_musicbot_plugins_customer ON musicbot_plugins (customer_id)');
            $this->addSql('CREATE INDEX idx_musicbot_plugins_instance ON musicbot_plugins (instance_id)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS musicbot_plugins');
        $this->addSql('DROP TABLE IF EXISTS musicbot_playlist_items');
        $this->addSql('DROP TABLE IF EXISTS musicbot_playlists');
        $this->addSql('DROP TABLE IF EXISTS musicbot_queue_items');
        $this->addSql('DROP TABLE IF EXISTS musicbot_tracks');
        $this->addSql('DROP TABLE IF EXISTS musicbot_connections');
        $this->addSql('DROP TABLE IF EXISTS musicbot_instances');
    }
}
