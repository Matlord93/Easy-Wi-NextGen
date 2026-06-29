<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260627120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expand musicbot playlist metadata and ordering support.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE musicbot_playlists ADD description LONGTEXT DEFAULT NULL, ADD sort_order INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE musicbot_playlist_items ADD metadata JSON DEFAULT NULL');
        $this->addSql("UPDATE musicbot_playlist_items SET metadata = '[]' WHERE metadata IS NULL");
        $this->addSql('ALTER TABLE musicbot_playlist_items CHANGE metadata metadata JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE musicbot_playlist_items DROP metadata');
        $this->addSql('ALTER TABLE musicbot_playlists DROP description, DROP sort_order');
    }
}
