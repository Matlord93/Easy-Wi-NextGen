<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260627180000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add musicbot_radio_history table for per-customer play tracking.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE musicbot_radio_history (
            id INT AUTO_INCREMENT NOT NULL,
            customer_id INT NOT NULL,
            instance_id INT DEFAULT NULL,
            station_id INT NOT NULL,
            played_at DATETIME NOT NULL,
            INDEX idx_radio_history_customer (customer_id),
            INDEX idx_radio_history_station (station_id),
            INDEX idx_radio_history_played_at (played_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE musicbot_radio_history ADD CONSTRAINT fk_radio_history_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE musicbot_radio_history ADD CONSTRAINT fk_radio_history_instance FOREIGN KEY (instance_id) REFERENCES musicbot_instances (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE musicbot_radio_history ADD CONSTRAINT fk_radio_history_station FOREIGN KEY (station_id) REFERENCES musicbot_radio_stations (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE musicbot_radio_history DROP FOREIGN KEY fk_radio_history_customer');
        $this->addSql('ALTER TABLE musicbot_radio_history DROP FOREIGN KEY fk_radio_history_instance');
        $this->addSql('ALTER TABLE musicbot_radio_history DROP FOREIGN KEY fk_radio_history_station');
        $this->addSql('DROP TABLE IF EXISTS musicbot_radio_history');
    }
}
