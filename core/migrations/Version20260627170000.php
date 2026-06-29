<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260627170000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add musicbot_radio_favorites table.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE musicbot_radio_favorites (
            id INT AUTO_INCREMENT NOT NULL,
            customer_id INT NOT NULL,
            station_id INT NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_radio_favorites_customer (customer_id),
            UNIQUE INDEX uniq_radio_favorite_customer_station (customer_id, station_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE musicbot_radio_favorites ADD CONSTRAINT fk_radio_favorites_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE musicbot_radio_favorites ADD CONSTRAINT fk_radio_favorites_station FOREIGN KEY (station_id) REFERENCES musicbot_radio_stations (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE musicbot_radio_favorites DROP FOREIGN KEY fk_radio_favorites_customer');
        $this->addSql('ALTER TABLE musicbot_radio_favorites DROP FOREIGN KEY fk_radio_favorites_station');
        $this->addSql('DROP TABLE IF EXISTS musicbot_radio_favorites');
    }
}
