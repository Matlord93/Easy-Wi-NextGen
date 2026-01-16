<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250328140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add TS3 node, virtual server, token, and viewer tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ts3_nodes (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, agent_base_url VARCHAR(255) NOT NULL, agent_api_token_encrypted LONGTEXT NOT NULL, download_url VARCHAR(255) NOT NULL, install_path VARCHAR(255) NOT NULL, instance_name VARCHAR(120) NOT NULL, service_name VARCHAR(120) NOT NULL, query_bind_ip VARCHAR(64) NOT NULL, query_port INT NOT NULL, installed_version VARCHAR(120) DEFAULT NULL, install_status VARCHAR(32) NOT NULL, running TINYINT(1) NOT NULL, last_error LONGTEXT DEFAULT NULL, admin_username VARCHAR(64) NOT NULL, admin_password_encrypted LONGTEXT DEFAULT NULL, admin_password_shown_once_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE ts3_virtual_servers (id INT AUTO_INCREMENT NOT NULL, node_id INT NOT NULL, customer_id INT NOT NULL, sid INT NOT NULL, name VARCHAR(120) NOT NULL, voice_port INT DEFAULT NULL, filetransfer_port INT DEFAULT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', archived_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_TS3_VIRTUAL_SERVERS_NODE (node_id), INDEX IDX_TS3_VIRTUAL_SERVERS_CUSTOMER (customer_id), INDEX IDX_TS3_VIRTUAL_SERVERS_SID (sid), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE ts3_tokens (id INT AUTO_INCREMENT NOT NULL, virtual_server_id INT NOT NULL, token_encrypted LONGTEXT NOT NULL, type VARCHAR(16) NOT NULL, active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', revoked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_TS3_TOKENS_SERVER (virtual_server_id), INDEX IDX_TS3_TOKENS_ACTIVE (active), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE ts3_viewers (id INT AUTO_INCREMENT NOT NULL, virtual_server_id INT NOT NULL, public_id VARCHAR(64) NOT NULL, enabled TINYINT(1) NOT NULL, cache_ttl_ms INT NOT NULL, domain_allowlist LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_TS3_VIEWERS_PUBLIC (public_id), UNIQUE INDEX UNIQ_TS3_VIEWERS_SERVER (virtual_server_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE ts3_virtual_servers ADD CONSTRAINT FK_TS3_VIRTUAL_SERVERS_NODE FOREIGN KEY (node_id) REFERENCES ts3_nodes (id)');
        $this->addSql('ALTER TABLE ts3_tokens ADD CONSTRAINT FK_TS3_TOKENS_SERVER FOREIGN KEY (virtual_server_id) REFERENCES ts3_virtual_servers (id)');
        $this->addSql('ALTER TABLE ts3_viewers ADD CONSTRAINT FK_TS3_VIEWERS_SERVER FOREIGN KEY (virtual_server_id) REFERENCES ts3_virtual_servers (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ts3_viewers DROP FOREIGN KEY FK_TS3_VIEWERS_SERVER');
        $this->addSql('ALTER TABLE ts3_tokens DROP FOREIGN KEY FK_TS3_TOKENS_SERVER');
        $this->addSql('ALTER TABLE ts3_virtual_servers DROP FOREIGN KEY FK_TS3_VIRTUAL_SERVERS_NODE');
        $this->addSql('DROP TABLE ts3_viewers');
        $this->addSql('DROP TABLE ts3_tokens');
        $this->addSql('DROP TABLE ts3_virtual_servers');
        $this->addSql('DROP TABLE ts3_nodes');
    }
}
