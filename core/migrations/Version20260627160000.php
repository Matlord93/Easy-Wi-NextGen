<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260627160000 extends AbstractMigration
{
    public function getDescription(): string { return 'Extend musicbot_radio_stations with catalog fields (global, active, country, language, tags, bitrate, format, resolved_stream_url, last_checked_at) and make customer_id nullable.'; }

    public function up(Schema $schema): void
    {
        // Drop the existing NOT NULL FK constraint on customer_id so we can allow nulls for global catalog entries
        $this->addSql('ALTER TABLE musicbot_radio_stations DROP FOREIGN KEY fk_musicbot_radio_stations_customer');
        $this->addSql('ALTER TABLE musicbot_radio_stations MODIFY customer_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE musicbot_radio_stations ADD CONSTRAINT fk_musicbot_radio_stations_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE');

        // Add new catalog columns
        $this->addSql('ALTER TABLE musicbot_radio_stations
            ADD COLUMN resolved_stream_url VARCHAR(2048) DEFAULT NULL AFTER stream_url,
            ADD COLUMN country VARCHAR(100) DEFAULT NULL AFTER logo_url,
            ADD COLUMN language VARCHAR(50) DEFAULT NULL AFTER country,
            ADD COLUMN tags JSON NOT NULL DEFAULT (JSON_ARRAY()) AFTER language,
            ADD COLUMN bitrate INT DEFAULT NULL AFTER tags,
            ADD COLUMN format VARCHAR(20) DEFAULT NULL AFTER bitrate,
            ADD COLUMN is_global TINYINT(1) NOT NULL DEFAULT 0 AFTER format,
            ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_global,
            ADD COLUMN last_checked_at DATETIME DEFAULT NULL AFTER last_played_at
        ');

        $this->addSql('CREATE INDEX idx_musicbot_radio_stations_global ON musicbot_radio_stations (is_global)');
        $this->addSql('CREATE INDEX idx_musicbot_radio_stations_active ON musicbot_radio_stations (is_active)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_musicbot_radio_stations_global ON musicbot_radio_stations');
        $this->addSql('DROP INDEX idx_musicbot_radio_stations_active ON musicbot_radio_stations');
        $this->addSql('ALTER TABLE musicbot_radio_stations
            DROP COLUMN resolved_stream_url,
            DROP COLUMN country,
            DROP COLUMN language,
            DROP COLUMN tags,
            DROP COLUMN bitrate,
            DROP COLUMN format,
            DROP COLUMN is_global,
            DROP COLUMN is_active,
            DROP COLUMN last_checked_at
        ');
        $this->addSql('ALTER TABLE musicbot_radio_stations DROP FOREIGN KEY fk_musicbot_radio_stations_customer');
        $this->addSql('ALTER TABLE musicbot_radio_stations MODIFY customer_id INT NOT NULL');
        $this->addSql('ALTER TABLE musicbot_radio_stations ADD CONSTRAINT fk_musicbot_radio_stations_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE');
    }
}
