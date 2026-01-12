<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250311123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create missing core tables for billing, services, and support.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE `databases` (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, engine VARCHAR(30) NOT NULL, host VARCHAR(255) NOT NULL, port INT NOT NULL, name VARCHAR(190) NOT NULL, username VARCHAR(190) NOT NULL, encrypted_password JSON NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_databases_customer_id (customer_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE api_tokens (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, name VARCHAR(190) NOT NULL, token_prefix VARCHAR(16) NOT NULL, token_hash VARCHAR(64) NOT NULL, encrypted_token JSON NOT NULL, scopes JSON NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', revoked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', rotated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_api_tokens_customer_id (customer_id), INDEX idx_api_tokens_token_hash (token_hash), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE backup_definitions (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, target_type VARCHAR(20) NOT NULL, target_id VARCHAR(64) NOT NULL, label VARCHAR(120) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_backup_definitions_customer_id (customer_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE backup_schedules (id INT AUTO_INCREMENT NOT NULL, definition_id INT NOT NULL, cron_expression VARCHAR(120) NOT NULL, retention_days INT NOT NULL, retention_count INT NOT NULL, enabled TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_backup_schedules_definition (definition_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE ddos_provider_credentials (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, provider VARCHAR(60) NOT NULL, encrypted_api_key JSON NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_ddos_provider_customer (customer_id, provider), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE port_pools (id INT AUTO_INCREMENT NOT NULL, node_id VARCHAR(64) NOT NULL, name VARCHAR(120) NOT NULL, start_port INT NOT NULL, end_port INT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_port_pools_node_id (node_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE port_blocks (id VARCHAR(32) NOT NULL, pool_id INT NOT NULL, customer_id INT NOT NULL, instance_id INT DEFAULT NULL, start_port INT NOT NULL, end_port INT NOT NULL, assigned_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', released_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_port_blocks_pool_id (pool_id), INDEX idx_port_blocks_customer_id (customer_id), INDEX idx_port_blocks_instance_id (instance_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE webspaces (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, node_id VARCHAR(64) NOT NULL, path VARCHAR(255) NOT NULL, php_version VARCHAR(20) NOT NULL, quota INT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_webspaces_customer_id (customer_id), INDEX idx_webspaces_node_id (node_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE domains (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, webspace_id INT NOT NULL, name VARCHAR(255) NOT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ssl_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_domains_customer_id (customer_id), INDEX idx_domains_webspace_id (webspace_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE dns_records (id INT AUTO_INCREMENT NOT NULL, domain_id INT NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(12) NOT NULL, content VARCHAR(255) NOT NULL, ttl INT NOT NULL, priority INT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_dns_records_domain_id (domain_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE mail_aliases (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, domain_id INT NOT NULL, local_part VARCHAR(190) NOT NULL, address VARCHAR(255) NOT NULL, destinations JSON NOT NULL, enabled TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_mail_aliases_customer_id (customer_id), INDEX idx_mail_aliases_domain_id (domain_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE mailboxes (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, domain_id INT NOT NULL, local_part VARCHAR(190) NOT NULL, address VARCHAR(255) NOT NULL, password_hash VARCHAR(255) NOT NULL, secret_payload JSON NOT NULL, quota INT NOT NULL, enabled TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_mailboxes_customer_id (customer_id), INDEX idx_mailboxes_domain_id (domain_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE user_sessions (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', revoked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_user_sessions_user_id (user_id), INDEX idx_user_sessions_token_hash (token_hash), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE jobs (id VARCHAR(32) NOT NULL, type VARCHAR(120) NOT NULL, payload JSON NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', locked_by VARCHAR(120) DEFAULT NULL, locked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', lock_token VARCHAR(64) DEFAULT NULL, lock_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_jobs_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE job_results (id INT AUTO_INCREMENT NOT NULL, job_id VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, output JSON NOT NULL, completed_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_job_results_job (job_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE log_indices (id VARCHAR(32) NOT NULL, agent_id VARCHAR(64) DEFAULT NULL, source VARCHAR(20) NOT NULL, scope_type VARCHAR(40) NOT NULL, scope_id VARCHAR(64) NOT NULL, log_name VARCHAR(80) NOT NULL, file_path VARCHAR(255) NOT NULL, byte_offset BIGINT NOT NULL, last_indexed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX log_indices_identity (agent_id, source, scope_type, scope_id, log_name), INDEX idx_log_indices_agent_id (agent_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE firewall_states (id INT AUTO_INCREMENT NOT NULL, node_id VARCHAR(64) NOT NULL, ports JSON NOT NULL, updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_firewall_states_node (node_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE instance_schedules (id INT AUTO_INCREMENT NOT NULL, instance_id INT NOT NULL, customer_id INT NOT NULL, action VARCHAR(20) NOT NULL, cron_expression VARCHAR(120) NOT NULL, time_zone VARCHAR(64) DEFAULT NULL, enabled TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_instance_schedules_instance_id (instance_id), INDEX idx_instance_schedules_customer_id (customer_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE metric_samples (id INT AUTO_INCREMENT NOT NULL, agent_id VARCHAR(64) NOT NULL, recorded_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', cpu_percent DOUBLE PRECISION DEFAULT NULL, memory_percent DOUBLE PRECISION DEFAULT NULL, disk_percent DOUBLE PRECISION DEFAULT NULL, net_bytes_sent BIGINT DEFAULT NULL, net_bytes_recv BIGINT DEFAULT NULL, payload JSON DEFAULT NULL, INDEX idx_metric_samples_agent_id (agent_id), INDEX idx_metric_samples_recorded_at (recorded_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE tickets (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, subject VARCHAR(160) NOT NULL, category VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, priority VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_message_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_tickets_customer_id (customer_id), INDEX idx_tickets_status (status), INDEX idx_tickets_last_message_at (last_message_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE ticket_messages (id INT AUTO_INCREMENT NOT NULL, ticket_id INT NOT NULL, author_id INT NOT NULL, body LONGTEXT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_ticket_messages_ticket_id (ticket_id), INDEX idx_ticket_messages_author_id (author_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE payments (id INT AUTO_INCREMENT NOT NULL, invoice_id INT NOT NULL, provider VARCHAR(60) NOT NULL, reference VARCHAR(120) NOT NULL, amount_cents INT NOT NULL, currency VARCHAR(3) NOT NULL, status VARCHAR(20) NOT NULL, received_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_payments_invoice_id (invoice_id), INDEX idx_payments_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE dunning_reminders (id INT AUTO_INCREMENT NOT NULL, invoice_id INT NOT NULL, level INT NOT NULL, fee_cents INT NOT NULL, grace_days INT NOT NULL, status VARCHAR(20) NOT NULL, sent_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_dunning_reminders_invoice_id (invoice_id), INDEX idx_dunning_reminders_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE ts3_instances (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, node_id VARCHAR(64) NOT NULL, name VARCHAR(80) NOT NULL, voice_port INT NOT NULL, query_port INT NOT NULL, file_port INT NOT NULL, database_mode VARCHAR(20) NOT NULL, database_host VARCHAR(120) DEFAULT NULL, database_port INT DEFAULT NULL, database_name VARCHAR(120) DEFAULT NULL, database_username VARCHAR(120) DEFAULT NULL, database_password JSON DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_ts3_instances_customer_id (customer_id), INDEX idx_ts3_instances_node_id (node_id), INDEX idx_ts3_instances_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE module_settings (module_key VARCHAR(40) NOT NULL, version VARCHAR(20) NOT NULL, enabled TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(module_key)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE tenants (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, branding JSON NOT NULL, domains JSON NOT NULL, mail_hostname VARCHAR(255) NOT NULL, invoice_prefix VARCHAR(40) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE `databases` ADD CONSTRAINT FK_DATABASES_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE api_tokens ADD CONSTRAINT FK_API_TOKENS_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE backup_definitions ADD CONSTRAINT FK_BACKUP_DEFINITIONS_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE backup_schedules ADD CONSTRAINT FK_BACKUP_SCHEDULES_DEFINITION FOREIGN KEY (definition_id) REFERENCES backup_definitions (id)');
        $this->addSql('ALTER TABLE ddos_provider_credentials ADD CONSTRAINT FK_DDOS_PROVIDER_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE port_pools ADD CONSTRAINT FK_PORT_POOLS_NODE FOREIGN KEY (node_id) REFERENCES agents (id)');
        $this->addSql('ALTER TABLE port_blocks ADD CONSTRAINT FK_PORT_BLOCKS_POOL FOREIGN KEY (pool_id) REFERENCES port_pools (id)');
        $this->addSql('ALTER TABLE port_blocks ADD CONSTRAINT FK_PORT_BLOCKS_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE port_blocks ADD CONSTRAINT FK_PORT_BLOCKS_INSTANCE FOREIGN KEY (instance_id) REFERENCES instances (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE webspaces ADD CONSTRAINT FK_WEBSPACES_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE webspaces ADD CONSTRAINT FK_WEBSPACES_NODE FOREIGN KEY (node_id) REFERENCES agents (id)');
        $this->addSql('ALTER TABLE domains ADD CONSTRAINT FK_DOMAINS_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE domains ADD CONSTRAINT FK_DOMAINS_WEBSPACE FOREIGN KEY (webspace_id) REFERENCES webspaces (id)');
        $this->addSql('ALTER TABLE dns_records ADD CONSTRAINT FK_DNS_RECORDS_DOMAIN FOREIGN KEY (domain_id) REFERENCES domains (id)');
        $this->addSql('ALTER TABLE mail_aliases ADD CONSTRAINT FK_MAIL_ALIASES_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE mail_aliases ADD CONSTRAINT FK_MAIL_ALIASES_DOMAIN FOREIGN KEY (domain_id) REFERENCES domains (id)');
        $this->addSql('ALTER TABLE mailboxes ADD CONSTRAINT FK_MAILBOXES_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE mailboxes ADD CONSTRAINT FK_MAILBOXES_DOMAIN FOREIGN KEY (domain_id) REFERENCES domains (id)');
        $this->addSql('ALTER TABLE user_sessions ADD CONSTRAINT FK_USER_SESSIONS_USER FOREIGN KEY (user_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE job_results ADD CONSTRAINT FK_JOB_RESULTS_JOB FOREIGN KEY (job_id) REFERENCES jobs (id)');
        $this->addSql('ALTER TABLE log_indices ADD CONSTRAINT FK_LOG_INDICES_AGENT FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE firewall_states ADD CONSTRAINT FK_FIREWALL_STATES_NODE FOREIGN KEY (node_id) REFERENCES agents (id)');
        $this->addSql('ALTER TABLE instance_schedules ADD CONSTRAINT FK_INSTANCE_SCHEDULES_INSTANCE FOREIGN KEY (instance_id) REFERENCES instances (id)');
        $this->addSql('ALTER TABLE instance_schedules ADD CONSTRAINT FK_INSTANCE_SCHEDULES_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE metric_samples ADD CONSTRAINT FK_METRIC_SAMPLES_AGENT FOREIGN KEY (agent_id) REFERENCES agents (id)');
        $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_TICKETS_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE ticket_messages ADD CONSTRAINT FK_TICKET_MESSAGES_TICKET FOREIGN KEY (ticket_id) REFERENCES tickets (id)');
        $this->addSql('ALTER TABLE ticket_messages ADD CONSTRAINT FK_TICKET_MESSAGES_AUTHOR FOREIGN KEY (author_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE payments ADD CONSTRAINT FK_PAYMENTS_INVOICE FOREIGN KEY (invoice_id) REFERENCES invoices (id)');
        $this->addSql('ALTER TABLE dunning_reminders ADD CONSTRAINT FK_DUNNING_REMINDERS_INVOICE FOREIGN KEY (invoice_id) REFERENCES invoices (id)');
        $this->addSql('ALTER TABLE ts3_instances ADD CONSTRAINT FK_TS3_INSTANCES_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE ts3_instances ADD CONSTRAINT FK_TS3_INSTANCES_NODE FOREIGN KEY (node_id) REFERENCES agents (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE port_blocks DROP FOREIGN KEY FK_PORT_BLOCKS_INSTANCE');
        $this->addSql('ALTER TABLE port_blocks DROP FOREIGN KEY FK_PORT_BLOCKS_POOL');
        $this->addSql('ALTER TABLE port_blocks DROP FOREIGN KEY FK_PORT_BLOCKS_CUSTOMER');
        $this->addSql('ALTER TABLE port_pools DROP FOREIGN KEY FK_PORT_POOLS_NODE');
        $this->addSql('ALTER TABLE webspaces DROP FOREIGN KEY FK_WEBSPACES_CUSTOMER');
        $this->addSql('ALTER TABLE webspaces DROP FOREIGN KEY FK_WEBSPACES_NODE');
        $this->addSql('ALTER TABLE domains DROP FOREIGN KEY FK_DOMAINS_CUSTOMER');
        $this->addSql('ALTER TABLE domains DROP FOREIGN KEY FK_DOMAINS_WEBSPACE');
        $this->addSql('ALTER TABLE dns_records DROP FOREIGN KEY FK_DNS_RECORDS_DOMAIN');
        $this->addSql('ALTER TABLE mail_aliases DROP FOREIGN KEY FK_MAIL_ALIASES_CUSTOMER');
        $this->addSql('ALTER TABLE mail_aliases DROP FOREIGN KEY FK_MAIL_ALIASES_DOMAIN');
        $this->addSql('ALTER TABLE mailboxes DROP FOREIGN KEY FK_MAILBOXES_CUSTOMER');
        $this->addSql('ALTER TABLE mailboxes DROP FOREIGN KEY FK_MAILBOXES_DOMAIN');
        $this->addSql('ALTER TABLE user_sessions DROP FOREIGN KEY FK_USER_SESSIONS_USER');
        $this->addSql('ALTER TABLE tickets DROP FOREIGN KEY FK_TICKETS_CUSTOMER');
        $this->addSql('ALTER TABLE ticket_messages DROP FOREIGN KEY FK_TICKET_MESSAGES_TICKET');
        $this->addSql('ALTER TABLE ticket_messages DROP FOREIGN KEY FK_TICKET_MESSAGES_AUTHOR');
        $this->addSql('ALTER TABLE payments DROP FOREIGN KEY FK_PAYMENTS_INVOICE');
        $this->addSql('ALTER TABLE dunning_reminders DROP FOREIGN KEY FK_DUNNING_REMINDERS_INVOICE');
        $this->addSql('ALTER TABLE ts3_instances DROP FOREIGN KEY FK_TS3_INSTANCES_CUSTOMER');
        $this->addSql('ALTER TABLE ts3_instances DROP FOREIGN KEY FK_TS3_INSTANCES_NODE');
        $this->addSql('ALTER TABLE firewall_states DROP FOREIGN KEY FK_FIREWALL_STATES_NODE');
        $this->addSql('ALTER TABLE instance_schedules DROP FOREIGN KEY FK_INSTANCE_SCHEDULES_INSTANCE');
        $this->addSql('ALTER TABLE instance_schedules DROP FOREIGN KEY FK_INSTANCE_SCHEDULES_CUSTOMER');
        $this->addSql('ALTER TABLE metric_samples DROP FOREIGN KEY FK_METRIC_SAMPLES_AGENT');
        $this->addSql('ALTER TABLE log_indices DROP FOREIGN KEY FK_LOG_INDICES_AGENT');
        $this->addSql('ALTER TABLE job_results DROP FOREIGN KEY FK_JOB_RESULTS_JOB');
        $this->addSql('ALTER TABLE backup_schedules DROP FOREIGN KEY FK_BACKUP_SCHEDULES_DEFINITION');
        $this->addSql('ALTER TABLE backup_definitions DROP FOREIGN KEY FK_BACKUP_DEFINITIONS_CUSTOMER');
        $this->addSql('ALTER TABLE ddos_provider_credentials DROP FOREIGN KEY FK_DDOS_PROVIDER_CUSTOMER');
        $this->addSql('ALTER TABLE api_tokens DROP FOREIGN KEY FK_API_TOKENS_CUSTOMER');
        $this->addSql('ALTER TABLE `databases` DROP FOREIGN KEY FK_DATABASES_CUSTOMER');

        $this->addSql('DROP TABLE tenants');
        $this->addSql('DROP TABLE module_settings');
        $this->addSql('DROP TABLE ts3_instances');
        $this->addSql('DROP TABLE dunning_reminders');
        $this->addSql('DROP TABLE payments');
        $this->addSql('DROP TABLE ticket_messages');
        $this->addSql('DROP TABLE tickets');
        $this->addSql('DROP TABLE metric_samples');
        $this->addSql('DROP TABLE instance_schedules');
        $this->addSql('DROP TABLE firewall_states');
        $this->addSql('DROP TABLE log_indices');
        $this->addSql('DROP TABLE job_results');
        $this->addSql('DROP TABLE jobs');
        $this->addSql('DROP TABLE user_sessions');
        $this->addSql('DROP TABLE mailboxes');
        $this->addSql('DROP TABLE mail_aliases');
        $this->addSql('DROP TABLE dns_records');
        $this->addSql('DROP TABLE domains');
        $this->addSql('DROP TABLE webspaces');
        $this->addSql('DROP TABLE port_blocks');
        $this->addSql('DROP TABLE port_pools');
        $this->addSql('DROP TABLE ddos_provider_credentials');
        $this->addSql('DROP TABLE backup_schedules');
        $this->addSql('DROP TABLE backup_definitions');
        $this->addSql('DROP TABLE api_tokens');
        $this->addSql('DROP TABLE `databases`');
    }
}
