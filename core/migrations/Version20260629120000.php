<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260629120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Synchronize Musicbot Doctrine mappings with migrated schema.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE musicbot_radio_stations CHANGE tags tags JSON NOT NULL, CHANGE is_global is_global TINYINT NOT NULL, CHANGE is_active is_active TINYINT NOT NULL, CHANGE is_favorite is_favorite TINYINT NOT NULL');
        $this->addSql('ALTER TABLE musicbot_playlists CHANGE sort_order sort_order INT NOT NULL');
        $this->addSql('ALTER TABLE musicbot_customer_limits ADD max_playlist_items INT DEFAULT NULL, ADD allow_stream TINYINT DEFAULT NULL, ADD allow_api TINYINT DEFAULT NULL');
        $this->addSql('ALTER TABLE musicbot_roles CHANGE is_default is_default TINYINT NOT NULL, CHANGE position position INT NOT NULL');
        $this->addSql('ALTER TABLE musicbot_autodj_settings CHANGE shuffle shuffle TINYINT NOT NULL, CHANGE `repeat` `repeat` TINYINT NOT NULL, CHANGE idle_seconds idle_seconds INT NOT NULL, CHANGE allow_youtube allow_youtube TINYINT NOT NULL, CHANGE allow_uploads allow_uploads TINYINT NOT NULL, CHANGE repeat_protection_window repeat_protection_window INT NOT NULL, CHANGE avoid_same_artist avoid_same_artist TINYINT NOT NULL');
        $this->addSql('ALTER TABLE musicbot_radio_history RENAME INDEX fk_radio_history_instance TO IDX_9952E6B73A51721D');
        $this->addSql('ALTER TABLE musicbot_radio_favorites RENAME INDEX fk_radio_favorites_station TO IDX_450C46DD21BDB235');
        $this->addSql('ALTER TABLE musicbot_role_assignments DROP FOREIGN KEY `fk_mra_granted_by`');
        $this->addSql('DROP INDEX fk_mra_granted_by ON musicbot_role_assignments');
        $this->addSql('ALTER TABLE musicbot_role_assignments CHANGE granted_by granted_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE musicbot_role_assignments ADD CONSTRAINT FK_366CE4C53151C11F FOREIGN KEY (granted_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_366CE4C53151C11F ON musicbot_role_assignments (granted_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE musicbot_role_assignments DROP FOREIGN KEY FK_366CE4C53151C11F');
        $this->addSql('DROP INDEX IDX_366CE4C53151C11F ON musicbot_role_assignments');
        $this->addSql('ALTER TABLE musicbot_role_assignments CHANGE granted_by_id granted_by INT DEFAULT NULL');
        $this->addSql('ALTER TABLE musicbot_role_assignments ADD CONSTRAINT fk_mra_granted_by FOREIGN KEY (granted_by) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX fk_mra_granted_by ON musicbot_role_assignments (granted_by)');
        $this->addSql('ALTER TABLE musicbot_radio_favorites RENAME INDEX IDX_450C46DD21BDB235 TO fk_radio_favorites_station');
        $this->addSql('ALTER TABLE musicbot_radio_history RENAME INDEX IDX_9952E6B73A51721D TO fk_radio_history_instance');
        $this->addSql('ALTER TABLE musicbot_customer_limits DROP max_playlist_items, DROP allow_stream, DROP allow_api');
    }
}
