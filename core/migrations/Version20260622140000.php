<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260622140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add SDK client install tracking fields to Musicbot TeamSpeak backend config.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE musicbot_teamspeak_backend_configs ADD sdk_client_install_enabled TINYINT(1) DEFAULT 0 NOT NULL, ADD sdk_client_version VARCHAR(32) DEFAULT '3.5.2' NOT NULL, ADD sdk_client_download_url VARCHAR(1024) DEFAULT 'https://files.teamspeak-services.com/releases/sdk/3.5.2/teamspeak-sdk-3.5.2.tar.gz' NOT NULL, ADD sdk_client_expected_sha256 VARCHAR(128) DEFAULT NULL, ADD sdk_client_install_path VARCHAR(1024) DEFAULT '/opt/easywi/musicbot/teamspeak-client/sdk/' NOT NULL, ADD sdk_client_status VARCHAR(64) DEFAULT 'sdk_client_not_installed' NOT NULL, ADD sdk_client_last_error LONGTEXT DEFAULT NULL, ADD sdk_client_last_installed_at DATETIME DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE musicbot_teamspeak_backend_configs DROP sdk_client_install_enabled, DROP sdk_client_version, DROP sdk_client_download_url, DROP sdk_client_expected_sha256, DROP sdk_client_install_path, DROP sdk_client_status, DROP sdk_client_last_error, DROP sdk_client_last_installed_at');
    }
}
