<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260623000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add external_client_bridge fields to Musicbot TeamSpeak backend config (bridge_path, official_client_binary_path, official_client_runscript_path, audio_backend).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE musicbot_teamspeak_backend_configs
            ADD bridge_path VARCHAR(1024) DEFAULT '/usr/local/bin/easywi-teamspeak-bridge' NOT NULL,
            ADD official_client_binary_path VARCHAR(1024) DEFAULT NULL,
            ADD official_client_runscript_path VARCHAR(1024) DEFAULT NULL,
            ADD audio_backend VARCHAR(64) DEFAULT 'pulseaudio_virtual_source' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE musicbot_teamspeak_backend_configs
            DROP bridge_path,
            DROP official_client_binary_path,
            DROP official_client_runscript_path,
            DROP audio_backend');
    }
}
