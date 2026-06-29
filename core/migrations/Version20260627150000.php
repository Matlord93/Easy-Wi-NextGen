<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260627150000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add musicbot_radio_stations table for webradio sender management.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE musicbot_radio_stations (
            id INT AUTO_INCREMENT NOT NULL,
            customer_id INT NOT NULL,
            instance_id INT DEFAULT NULL,
            name VARCHAR(190) NOT NULL,
            stream_url VARCHAR(2048) NOT NULL,
            genre VARCHAR(100) DEFAULT NULL,
            description VARCHAR(500) DEFAULT NULL,
            homepage VARCHAR(255) DEFAULT NULL,
            logo_url VARCHAR(2048) DEFAULT NULL,
            is_favorite TINYINT(1) NOT NULL DEFAULT 0,
            last_played_at DATETIME DEFAULT NULL,
            metadata JSON NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_musicbot_radio_stations_customer (customer_id),
            INDEX idx_musicbot_radio_stations_instance (instance_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE musicbot_radio_stations ADD CONSTRAINT fk_musicbot_radio_stations_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE musicbot_radio_stations ADD CONSTRAINT fk_musicbot_radio_stations_instance FOREIGN KEY (instance_id) REFERENCES musicbot_instances (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE musicbot_radio_stations DROP FOREIGN KEY fk_musicbot_radio_stations_customer');
        $this->addSql('ALTER TABLE musicbot_radio_stations DROP FOREIGN KEY fk_musicbot_radio_stations_instance');
        $this->addSql('DROP TABLE IF EXISTS musicbot_radio_stations');
    }
}
