<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260622130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional official TeamSpeak client install tracking to Musicbot backend config.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE musicbot_teamspeak_backend_configs ADD official_client_install_enabled TINYINT(1) DEFAULT 0 NOT NULL, ADD official_client_version VARCHAR(32) DEFAULT '3.6.2' NOT NULL, ADD official_client_download_url VARCHAR(1024) DEFAULT 'https://files.teamspeak-services.com/releases/client/3.6.2/TeamSpeak3-Client-linux_amd64-3.6.2.run' NOT NULL, ADD official_client_expected_sha256 VARCHAR(128) DEFAULT NULL, ADD official_client_install_path VARCHAR(1024) DEFAULT '/opt/easywi/musicbot/teamspeak-client/official-client/' NOT NULL, ADD official_client_status VARCHAR(64) DEFAULT 'official_client_not_installed' NOT NULL, ADD official_client_last_error LONGTEXT DEFAULT NULL, ADD official_client_last_installed_at DATETIME DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE musicbot_teamspeak_backend_configs DROP official_client_install_enabled, DROP official_client_version, DROP official_client_download_url, DROP official_client_expected_sha256, DROP official_client_install_path, DROP official_client_status, DROP official_client_last_error, DROP official_client_last_installed_at');
    }
}
