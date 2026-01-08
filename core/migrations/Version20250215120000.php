<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250215120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add public_servers table for manual server directory entries.';
    }

    public function up(Schema $schema): void
    {
$this->addSql('
    CREATE TABLE public_servers (
        id INT AUTO_INCREMENT NOT NULL,
        created_by_id INT NOT NULL,
        site_id INT NOT NULL,
        name VARCHAR(160) NOT NULL,
        category VARCHAR(80) NOT NULL,
        game_key VARCHAR(120) NOT NULL,
        ip VARCHAR(64) NOT NULL,
        port INT NOT NULL,
        query_type VARCHAR(40) NOT NULL,
        query_port INT DEFAULT NULL,
        visible_public TINYINT(1) NOT NULL,
        visible_logged_in TINYINT(1) NOT NULL,
        sort_order INT NOT NULL,
        notes_internal LONGTEXT DEFAULT NULL,
        status_cache JSON NOT NULL,
        last_checked_at DATETIME DEFAULT NULL,
        check_interval_seconds INT NOT NULL,
        INDEX idx_public_servers_site_id (site_id),
        INDEX idx_public_servers_visibility (visible_public, visible_logged_in),
        INDEX idx_public_servers_game_key (game_key),
        INDEX idx_public_servers_created_by (created_by_id),
        PRIMARY KEY(id)
    ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
');
        $this->addSql('ALTER TABLE public_servers ADD CONSTRAINT fk_public_servers_created_by FOREIGN KEY (created_by_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public_servers DROP FOREIGN KEY fk_public_servers_created_by');
        $this->addSql('DROP TABLE public_servers');
    }
}
