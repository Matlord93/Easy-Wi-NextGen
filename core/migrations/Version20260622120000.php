<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260622120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add managed Musicbot TeamSpeak client backend configuration.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE musicbot_teamspeak_backend_configs (id INT AUTO_INCREMENT NOT NULL, node_id VARCHAR(64) NOT NULL, backend_type VARCHAR(32) NOT NULL, backend_path VARCHAR(1024) NOT NULL, library_path VARCHAR(1024) NOT NULL, opus_library_path VARCHAR(1024) DEFAULT NULL, identity_path VARCHAR(1024) DEFAULT NULL, install_path VARCHAR(1024) NOT NULL, binary_path VARCHAR(1024) NOT NULL, version VARCHAR(128) DEFAULT NULL, checksum VARCHAR(128) DEFAULT NULL, auto_install_enabled TINYINT(1) DEFAULT 0 NOT NULL, status VARCHAR(255) NOT NULL, last_error LONGTEXT DEFAULT NULL, last_checked_at DATETIME DEFAULT NULL, INDEX idx_musicbot_ts_backend_node (node_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE musicbot_teamspeak_backend_configs ADD CONSTRAINT FK_MUSICBOT_TS_BACKEND_NODE FOREIGN KEY (node_id) REFERENCES agents (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE musicbot_teamspeak_backend_configs DROP FOREIGN KEY FK_MUSICBOT_TS_BACKEND_NODE');
        $this->addSql('DROP TABLE musicbot_teamspeak_backend_configs');
    }
}
