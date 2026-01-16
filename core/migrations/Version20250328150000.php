<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250328150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add SinusBot nodes and instances tables with TS3 client dependency status.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sinusbot_nodes (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, agent_base_url VARCHAR(255) NOT NULL, agent_api_token_encrypted LONGTEXT NOT NULL, download_url VARCHAR(255) NOT NULL, install_path VARCHAR(255) NOT NULL, instance_root VARCHAR(255) NOT NULL, web_bind_ip VARCHAR(64) NOT NULL, web_port_base INT NOT NULL, installed_version VARCHAR(120) DEFAULT NULL, install_status VARCHAR(32) NOT NULL, last_error LONGTEXT DEFAULT NULL, admin_username VARCHAR(120) DEFAULT NULL, admin_password_encrypted LONGTEXT DEFAULT NULL, ts3_client_installed TINYINT(1) NOT NULL, ts3_client_version VARCHAR(120) DEFAULT NULL, ts3_client_path VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE sinusbot_instances (id INT AUTO_INCREMENT NOT NULL, node_id INT NOT NULL, customer_id INT NOT NULL, instance_id VARCHAR(64) NOT NULL, name VARCHAR(120) NOT NULL, running TINYINT(1) NOT NULL, web_port INT NOT NULL, public_url VARCHAR(255) DEFAULT NULL, connect_type VARCHAR(8) NOT NULL, connect_host VARCHAR(255) NOT NULL, connect_voice_port INT NOT NULL, connect_server_password_encrypted LONGTEXT DEFAULT NULL, connect_privilege_key_encrypted LONGTEXT DEFAULT NULL, nickname VARCHAR(120) DEFAULT NULL, default_channel VARCHAR(255) DEFAULT NULL, volume INT DEFAULT NULL, autostart TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', archived_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_9F589B1B9F16E290 (instance_id), INDEX IDX_9F589B1B460D9FD (node_id), INDEX IDX_9F589B1B9395C3F3 (customer_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE sinusbot_instances ADD CONSTRAINT FK_9F589B1B460D9FD FOREIGN KEY (node_id) REFERENCES sinusbot_nodes (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sinusbot_instances DROP FOREIGN KEY FK_9F589B1B460D9FD');
        $this->addSql('DROP TABLE sinusbot_instances');
        $this->addSql('DROP TABLE sinusbot_nodes');
    }
}
