<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260627130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expand musicbot AutoDJ settings for queue triggers, filters, and fallbacks.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE musicbot_autodj_settings ADD shuffle TINYINT(1) DEFAULT 1 NOT NULL, ADD `repeat` TINYINT(1) DEFAULT 0 NOT NULL, ADD idle_seconds INT DEFAULT 60 NOT NULL, ADD volume_override INT DEFAULT NULL, ADD time_window_start VARCHAR(5) DEFAULT NULL, ADD time_window_end VARCHAR(5) DEFAULT NULL, ADD webradio_fallback_url VARCHAR(2048) DEFAULT NULL, ADD allow_youtube TINYINT(1) DEFAULT 0 NOT NULL, ADD allow_uploads TINYINT(1) DEFAULT 1 NOT NULL, ADD repeat_protection_window INT DEFAULT 5 NOT NULL, ADD avoid_same_artist TINYINT(1) DEFAULT 0 NOT NULL, ADD playlist_ids JSON DEFAULT NULL");
        $this->addSql("UPDATE musicbot_autodj_settings SET playlist_ids = JSON_ARRAY(fallback_playlist_id) WHERE fallback_playlist_id IS NOT NULL AND playlist_ids IS NULL");
        $this->addSql("UPDATE musicbot_autodj_settings SET playlist_ids = '[]' WHERE playlist_ids IS NULL");
        $this->addSql('ALTER TABLE musicbot_autodj_settings CHANGE playlist_ids playlist_ids JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE musicbot_autodj_settings DROP shuffle, DROP `repeat`, DROP idle_seconds, DROP volume_override, DROP time_window_start, DROP time_window_end, DROP webradio_fallback_url, DROP allow_youtube, DROP allow_uploads, DROP `repeat`_protection_window, DROP avoid_same_artist, DROP playlist_ids');
    }
}
