<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20270301091000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sync schema: create missing tables (mail_*, cms_templates, webspace_vhosts, blog_post_tags, messenger_messages), rename indexes to Doctrine conventions, fix DATETIME column types, add/drop columns.';
    }

    public function up(Schema $schema): void
    {
        // ── 1. Create new tables ──────────────────────────────────────────────

        if (!$schema->hasTable('cms_templates')) {
            $this->addSql('CREATE TABLE cms_templates (id INT AUTO_INCREMENT NOT NULL, template_key VARCHAR(64) NOT NULL, name VARCHAR(160) NOT NULL, active TINYINT NOT NULL, preview_path VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_cms_templates_template_key (template_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$schema->hasTable('messenger_messages')) {
            $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$schema->hasTable('mail_metric_buckets')) {
            $this->addSql('CREATE TABLE mail_metric_buckets (id BIGINT AUTO_INCREMENT NOT NULL, bucket_start DATETIME NOT NULL, bucket_size_seconds INT NOT NULL, metric_name VARCHAR(64) NOT NULL, metric_value DOUBLE PRECISION NOT NULL, dimensions JSON NOT NULL, created_at DATETIME NOT NULL, domain_id INT DEFAULT NULL, INDEX IDX_CC48A47115F0EE5 (domain_id), INDEX idx_mail_metric_buckets_bucket (bucket_start, bucket_size_seconds), INDEX idx_mail_metric_buckets_metric (metric_name, bucket_start), INDEX idx_mail_metric_buckets_domain_metric (domain_id, metric_name, bucket_start), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$schema->hasTable('forum_post_reports')) {
            $this->addSql('CREATE TABLE forum_post_reports (id INT AUTO_INCREMENT NOT NULL, reason VARCHAR(120) NOT NULL, details LONGTEXT DEFAULT NULL, status VARCHAR(20) NOT NULL, reporter_ip_hash VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL, post_id INT NOT NULL, reporter_id INT DEFAULT NULL, resolved_by_id INT DEFAULT NULL, INDEX IDX_F429E6314B89032C (post_id), INDEX IDX_F429E631E1CFE6F5 (reporter_id), INDEX IDX_F429E6316713A32B (resolved_by_id), INDEX idx_forum_reports_status_created (status, created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$schema->hasTable('mail_policies')) {
            $this->addSql('CREATE TABLE mail_policies (id INT AUTO_INCREMENT NOT NULL, require_tls TINYINT DEFAULT 0 NOT NULL, smtp_enabled TINYINT DEFAULT 1 NOT NULL, max_recipients INT DEFAULT 100 NOT NULL, max_hourly_emails INT DEFAULT 500 NOT NULL, allow_external_forwarding TINYINT DEFAULT 0 NOT NULL, spam_protection_level VARCHAR(8) DEFAULT \'med\' NOT NULL, greylisting_enabled TINYINT DEFAULT 1 NOT NULL, abuse_policy_enabled TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_id INT NOT NULL, domain_id INT NOT NULL, INDEX IDX_3D5C7E867E3C61F9 (owner_id), INDEX idx_mail_policy_owner_domain (owner_id, domain_id), UNIQUE INDEX uniq_mail_policy_domain (domain_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$schema->hasTable('webspace_vhosts')) {
            $this->addSql('CREATE TABLE webspace_vhosts (id INT AUTO_INCREMENT NOT NULL, runtime VARCHAR(20) DEFAULT \'nginx\' NOT NULL, config_path VARCHAR(255) NOT NULL, deploy_status VARCHAR(20) DEFAULT \'pending\' NOT NULL, last_error LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, webspace_id INT NOT NULL, domain_id INT NOT NULL, INDEX IDX_5460C321BFB01CA3 (webspace_id), INDEX IDX_5460C321115F0EE5 (domain_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$schema->hasTable('mail_users')) {
            $this->addSql('CREATE TABLE mail_users (id INT AUTO_INCREMENT NOT NULL, local_part VARCHAR(190) NOT NULL, address VARCHAR(255) NOT NULL, password_hash VARCHAR(255) NOT NULL, quota_mb INT NOT NULL, enabled TINYINT DEFAULT 1 NOT NULL, last_auth_at DATETIME DEFAULT NULL, last_auth_ip VARCHAR(45) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, mailbox_id INT NOT NULL, customer_id INT NOT NULL, domain_id INT NOT NULL, INDEX IDX_204007869395C3F3 (customer_id), INDEX IDX_20400786115F0EE5 (domain_id), INDEX idx_mail_users_domain_enabled (domain_id, enabled), UNIQUE INDEX uniq_mail_users_address (address), UNIQUE INDEX uniq_mail_users_mailbox (mailbox_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$schema->hasTable('blog_post_tags')) {
            $this->addSql('CREATE TABLE blog_post_tags (post_id INT NOT NULL, tag_id INT NOT NULL, INDEX IDX_3971B624B89032C (post_id), INDEX IDX_3971B62BAD26311 (tag_id), PRIMARY KEY(post_id, tag_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$schema->hasTable('cms_template_versions')) {
            $this->addSql('CREATE TABLE cms_template_versions (id INT AUTO_INCREMENT NOT NULL, version_number INT NOT NULL, storage_path VARCHAR(255) NOT NULL, checksum VARCHAR(64) NOT NULL, manifest JSON NOT NULL, active TINYINT NOT NULL, deployed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, template_id INT NOT NULL, INDEX IDX_F6CAF7625DA0FB8 (template_id), UNIQUE INDEX uniq_template_version (template_id, version_number), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$schema->hasTable('webspace_certificates')) {
            $this->addSql('CREATE TABLE webspace_certificates (id INT AUTO_INCREMENT NOT NULL, provider VARCHAR(20) DEFAULT \'acme\' NOT NULL, status VARCHAR(20) DEFAULT \'pending\' NOT NULL, expires_at DATETIME DEFAULT NULL, last_error LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, domain_id INT NOT NULL, INDEX IDX_48DF0F3C115F0EE5 (domain_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$schema->hasTable('mail_forwardings')) {
            $this->addSql('CREATE TABLE mail_forwardings (id INT AUTO_INCREMENT NOT NULL, source_local_part VARCHAR(190) NOT NULL, destination VARCHAR(255) NOT NULL, enabled TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, customer_id INT NOT NULL, domain_id INT NOT NULL, INDEX IDX_DF5B053E9395C3F3 (customer_id), INDEX IDX_DF5B053E115F0EE5 (domain_id), INDEX idx_mail_forwardings_domain_enabled (domain_id, enabled), UNIQUE INDEX uniq_mail_forwarding_route (domain_id, source_local_part, destination), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$schema->hasTable('mail_dkim_keys')) {
            $this->addSql('CREATE TABLE mail_dkim_keys (id INT AUTO_INCREMENT NOT NULL, selector VARCHAR(64) NOT NULL, public_key LONGTEXT NOT NULL, algorithm VARCHAR(16) DEFAULT \'rsa\' NOT NULL, key_bits INT DEFAULT 2048 NOT NULL, fingerprint_sha256 VARCHAR(64) NOT NULL, private_key_path VARCHAR(255) NOT NULL, agent_node_id VARCHAR(64) DEFAULT NULL, active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, rotated_at DATETIME DEFAULT NULL, deactivated_at DATETIME DEFAULT NULL, customer_id INT NOT NULL, domain_id INT NOT NULL, INDEX IDX_9710B0D09395C3F3 (customer_id), INDEX IDX_9710B0D0115F0EE5 (domain_id), INDEX idx_mail_dkim_domain_active (domain_id, active), UNIQUE INDEX uniq_mail_dkim_domain_selector (domain_id, selector), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$schema->hasTable('mail_logs')) {
            $this->addSql('CREATE TABLE mail_logs (id BIGINT AUTO_INCREMENT NOT NULL, level VARCHAR(16) NOT NULL, source VARCHAR(32) NOT NULL, message LONGTEXT NOT NULL, event_type VARCHAR(32) NOT NULL, payload JSON NOT NULL, created_at DATETIME NOT NULL, domain_id INT NOT NULL, user_id INT DEFAULT NULL, INDEX IDX_5DF45535115F0EE5 (domain_id), INDEX IDX_5DF45535A76ED395 (user_id), INDEX idx_mail_logs_created_at (created_at), INDEX idx_mail_logs_domain_created_at (domain_id, created_at), INDEX idx_mail_logs_level_created_at (level, created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$schema->hasTable('mail_rate_limits')) {
            $this->addSql('CREATE TABLE mail_rate_limits (id INT AUTO_INCREMENT NOT NULL, max_mails_per_hour INT DEFAULT 240 NOT NULL, max_recipients_per_mail INT DEFAULT 100 NOT NULL, burst_per_minute INT DEFAULT 40 NOT NULL, greylisting_enabled TINYINT DEFAULT 0 NOT NULL, tls_only TINYINT DEFAULT 0 NOT NULL, strict_spf_dkim TINYINT DEFAULT 1 NOT NULL, dmarc_policy VARCHAR(16) DEFAULT \'quarantine\' NOT NULL, counter_window_start DATETIME NOT NULL, current_count INT DEFAULT 0 NOT NULL, blocked_until DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, customer_id INT NOT NULL, mailbox_id INT NOT NULL, INDEX IDX_40EB740D9395C3F3 (customer_id), INDEX idx_mail_rate_limits_counter_window (counter_window_start), INDEX idx_mail_rate_limits_blocked_until (blocked_until), UNIQUE INDEX uniq_mail_rate_limits_mailbox (mailbox_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        // ── 2. FK constraints for new tables ─────────────────────────────────
        // NOTE: $schema reflects the PRE-migration DB state; newly created tables
        // return hasTable()=false, so these must be added unconditionally.

        $this->addSql('ALTER TABLE mail_metric_buckets ADD CONSTRAINT FK_CC48A47115F0EE5 FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE forum_post_reports ADD CONSTRAINT FK_F429E6314B89032C FOREIGN KEY (post_id) REFERENCES forum_posts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE forum_post_reports ADD CONSTRAINT FK_F429E631E1CFE6F5 FOREIGN KEY (reporter_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE forum_post_reports ADD CONSTRAINT FK_F429E6316713A32B FOREIGN KEY (resolved_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mail_policies ADD CONSTRAINT FK_3D5C7E867E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mail_policies ADD CONSTRAINT FK_3D5C7E86115F0EE5 FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE webspace_vhosts ADD CONSTRAINT FK_5460C321BFB01CA3 FOREIGN KEY (webspace_id) REFERENCES webspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE webspace_vhosts ADD CONSTRAINT FK_5460C321115F0EE5 FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mail_users ADD CONSTRAINT FK_2040078666EC35CC FOREIGN KEY (mailbox_id) REFERENCES mailboxes (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mail_users ADD CONSTRAINT FK_204007869395C3F3 FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mail_users ADD CONSTRAINT FK_20400786115F0EE5 FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE blog_post_tags ADD CONSTRAINT FK_3971B624B89032C FOREIGN KEY (post_id) REFERENCES cms_posts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE blog_post_tags ADD CONSTRAINT FK_3971B62BAD26311 FOREIGN KEY (tag_id) REFERENCES blog_tags (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cms_template_versions ADD CONSTRAINT FK_F6CAF7625DA0FB8 FOREIGN KEY (template_id) REFERENCES cms_templates (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE webspace_certificates ADD CONSTRAINT FK_48DF0F3C115F0EE5 FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mail_forwardings ADD CONSTRAINT FK_DF5B053E9395C3F3 FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mail_forwardings ADD CONSTRAINT FK_DF5B053E115F0EE5 FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mail_dkim_keys ADD CONSTRAINT FK_9710B0D09395C3F3 FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mail_dkim_keys ADD CONSTRAINT FK_9710B0D0115F0EE5 FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mail_logs ADD CONSTRAINT FK_5DF45535115F0EE5 FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mail_logs ADD CONSTRAINT FK_5DF45535A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mail_rate_limits ADD CONSTRAINT FK_40EB740D9395C3F3 FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mail_rate_limits ADD CONSTRAINT FK_40EB740D66EC35CC FOREIGN KEY (mailbox_id) REFERENCES mailboxes (id) ON DELETE CASCADE');

        // ── 3. Drop legacy table mail_forwards ───────────────────────────────

        if ($schema->hasTable('mail_forwards')) {
            $this->addSql('ALTER TABLE mail_forwards DROP FOREIGN KEY `FK_MAIL_FORWARDS_DOMAIN`');
            $this->addSql('DROP TABLE mail_forwards');
        }

        // ── 4. Rename indexes to Doctrine naming conventions ─────────────────

        $this->addSql('ALTER TABLE hp_module RENAME INDEX idx_hp_module_node_id TO IDX_C599B857460D9FD7');
        $this->addSql('ALTER TABLE hp_job RENAME INDEX idx_hp_job_node_id TO IDX_3B40EA45460D9FD7');
        $this->addSql('ALTER TABLE hp_secret RENAME INDEX uniq_hp_secret_name TO UNIQ_951F769A5E237E06');
        $this->addSql('ALTER TABLE hp_role RENAME INDEX uniq_hp_role_name TO UNIQ_E279DD515E237E06');
        $this->addSql('ALTER TABLE hp_agent RENAME INDEX uniq_hp_agent_uuid TO UNIQ_973565EE7CE4EA45');
        $this->addSql('ALTER TABLE hp_agent RENAME INDEX idx_hp_agent_node_id TO IDX_973565EE460D9FD7');
        $this->addSql('ALTER TABLE hp_metric_sample RENAME INDEX idx_hp_metric_sample_node_id TO IDX_AFB3F72F460D9FD7');
        $this->addSql('ALTER TABLE hp_user RENAME INDEX uniq_hp_user_email TO UNIQ_38838172E7927C74');
        $this->addSql('ALTER TABLE hp_user RENAME INDEX idx_hp_user_role_id TO IDX_38838172D60322AC');
        $this->addSql('ALTER TABLE hp_node RENAME INDEX uniq_hp_node_name TO UNIQ_306FBF7E5E237E06');
        $this->addSql('ALTER TABLE hp_job_run RENAME INDEX idx_hp_job_run_job_id TO IDX_54D0E662BE04EA9');
        $this->addSql('ALTER TABLE game_profiles RENAME INDEX uniq_game_profiles_key TO UNIQ_20747A1691CAB86C');
        $this->addSql('ALTER TABLE ts3_instances RENAME INDEX idx_ts3_instances_customer_id TO IDX_4354E78B9395C3F3');
        $this->addSql('ALTER TABLE ts3_instances RENAME INDEX idx_ts3_instances_node_id TO IDX_4354E78B460D9FD7');
        $this->addSql('ALTER TABLE instance_sftp_credentials RENAME INDEX uniq_instance_sftp_credentials_instance TO UNIQ_9B08D0A23A51721D');
        $this->addSql('ALTER TABLE backup_schedules RENAME INDEX uniq_backup_schedules_definition TO UNIQ_6DFF0347D11EA911');
        $this->addSql('ALTER TABLE backup_schedules RENAME INDEX idx_79ff3b0a62baa4e5 TO IDX_6DFF0347494AD4AF');
        $this->addSql('ALTER TABLE mailboxes RENAME INDEX idx_mailboxes_customer_id TO IDX_3DAF519B9395C3F3');
        $this->addSql('ALTER TABLE mailboxes RENAME INDEX idx_mailboxes_domain_id TO IDX_3DAF519B115F0EE5');
        $this->addSql('ALTER TABLE incident_components RENAME INDEX idx_5b0588b8d19dcc98 TO IDX_A085824C59E53FB9');
        $this->addSql('ALTER TABLE incident_components RENAME INDEX idx_5b0588b7cb403 TO IDX_A085824C2815B700');
        $this->addSql('ALTER TABLE maintenance_window_components RENAME INDEX idx_9c8e50b7fb56d6 TO IDX_DDB5D8A8C4C83B3C');
        $this->addSql('ALTER TABLE maintenance_window_components RENAME INDEX idx_9c8e50cb7cb403 TO IDX_DDB5D8A82815B700');
        $this->addSql('ALTER TABLE dns_records RENAME INDEX idx_dns_records_domain_id TO IDX_7DF9D7115F0EE5');
        $this->addSql('ALTER TABLE sinusbot_nodes RENAME INDEX fk_d805e71d3414710b TO IDX_D805E71D3414710B');
        $this->addSql('ALTER TABLE shop_products RENAME INDEX idx_shop_products_category_id TO IDX_6802A0CC12469DE2');
        $this->addSql('ALTER TABLE shop_products RENAME INDEX idx_shop_products_template_id TO IDX_6802A0CC5DA0FB8');
        $this->addSql('ALTER TABLE shop_products RENAME INDEX idx_shop_products_node_id TO IDX_6802A0CC460D9FD7');
        $this->addSql('ALTER TABLE voice_rate_limit_states RENAME INDEX idx_efae8d0a5e237e06 TO IDX_4B1DB3A2460D9FD7');
        $this->addSql('ALTER TABLE consent_logs RENAME INDEX idx_4bb08c0a76ed395 TO IDX_598C4650A76ED395');
        $this->addSql('ALTER TABLE ts3_nodes RENAME INDEX fk_d6d5e7083414710b TO IDX_D6D5E7083414710B');
        $this->addSql('ALTER TABLE voice_instances RENAME INDEX idx_14580a3a9395c3f3 TO IDX_A24AB509395C3F3');
        $this->addSql('ALTER TABLE voice_instances RENAME INDEX idx_14580a3a5e237e06 TO IDX_A24AB50460D9FD7');
        $this->addSql('ALTER TABLE mail_aliases RENAME INDEX idx_mail_aliases_customer_id TO IDX_85AF3A569395C3F3');
        $this->addSql('ALTER TABLE mail_aliases RENAME INDEX idx_mail_aliases_domain_id TO IDX_85AF3A56115F0EE5');
        $this->addSql('ALTER TABLE cms_blocks RENAME INDEX idx_cms_blocks_page_id TO IDX_FC1B8A00C4663E4');
        $this->addSql('ALTER TABLE backup_definitions RENAME INDEX idx_backup_definitions_customer_id TO IDX_9F3A5A449395C3F3');
        $this->addSql('ALTER TABLE backup_definitions RENAME INDEX idx_91ba3aa0b514f4f0 TO IDX_9F3A5A44494AD4AF');
        $this->addSql('ALTER TABLE log_indices RENAME INDEX idx_log_indices_agent_id TO IDX_38FA23EC3414710B');
        $this->addSql('ALTER TABLE invoice_archives RENAME INDEX uniq_invoice_archives_invoice TO UNIQ_FD4AB6612989F1FD');
        $this->addSql('ALTER TABLE dunning_reminders RENAME INDEX idx_dunning_reminders_invoice_id TO IDX_7658F4E82989F1FD');
        $this->addSql('ALTER TABLE ts6_tokens RENAME INDEX idx_ts6_tokens_server TO IDX_58A1C10D4BFC982F');
        $this->addSql('ALTER TABLE instances RENAME INDEX idx_instances_customer_id TO IDX_7A2700699395C3F3');
        $this->addSql('ALTER TABLE instances RENAME INDEX idx_instances_template_id TO IDX_7A2700695DA0FB8');
        $this->addSql('ALTER TABLE instances RENAME INDEX idx_instances_node_id TO IDX_7A270069460D9FD7');
        $this->addSql('ALTER TABLE instance_schedules RENAME INDEX idx_instance_schedules_instance_id TO IDX_3439219A3A51721D');
        $this->addSql('ALTER TABLE instance_schedules RENAME INDEX idx_instance_schedules_customer_id TO IDX_3439219A9395C3F3');
        $this->addSql('ALTER TABLE api_tokens RENAME INDEX idx_api_tokens_customer_id TO IDX_2CAD560E9395C3F3');
        $this->addSql('ALTER TABLE public_servers RENAME INDEX idx_public_servers_created_by TO IDX_1454AA45B03A8386');
        $this->addSql('ALTER TABLE ts6_viewers RENAME INDEX uniq_ts6_viewers_public TO UNIQ_744F1F96B5B48B91');
        $this->addSql('ALTER TABLE ts6_viewers RENAME INDEX uniq_ts6_viewers_server TO UNIQ_744F1F964BFC982F');
        $this->addSql('ALTER TABLE security_policy_revisions RENAME INDEX idx_e9f1d0bd4600c3e3 TO IDX_4209DEF9460D9FD7');
        $this->addSql('ALTER TABLE security_policy_revisions RENAME INDEX idx_e9f1d0bdb03a8386 TO IDX_4209DEF9B03A8386');
        $this->addSql('ALTER TABLE forum_posts RENAME INDEX fk_forum_posts_site TO IDX_90291C2DF6BD1646');
        $this->addSql('ALTER TABLE forum_posts RENAME INDEX idx_c960c4a697e9e282 TO IDX_90291C2DE2544CD6');
        $this->addSql('ALTER TABLE job_results RENAME INDEX uniq_job_results_job TO UNIQ_54C538FBE04EA9');
        $this->addSql('ALTER TABLE agent_registration_tokens RENAME INDEX idx_registration_tokens_bootstrap TO IDX_F6AFF610C1DEB8C7');
        $this->addSql('ALTER TABLE agent_registration_tokens RENAME INDEX idx_registration_tokens_hash TO idx_agent_registration_tokens_token_hash');
        $this->addSql('ALTER TABLE payments RENAME INDEX idx_payments_invoice_id TO IDX_65D29B322989F1FD');
        $this->addSql('ALTER TABLE quota_policies RENAME INDEX uniq_quota_policy_name TO UNIQ_35AB7255E237E06');
        $this->addSql('ALTER TABLE ts3_viewers RENAME INDEX uniq_ts3_viewers_public TO UNIQ_3ED85AD9B5B48B91');
        $this->addSql('ALTER TABLE ts3_viewers RENAME INDEX uniq_ts3_viewers_server TO UNIQ_3ED85AD94BFC982F');
        $this->addSql('ALTER TABLE ticket_attachments RENAME INDEX idx_ticket_attachments_ticket_id TO IDX_2B54FCA9700047D2');
        $this->addSql('ALTER TABLE ticket_attachments RENAME INDEX idx_ticket_attachments_message_id TO IDX_2B54FCA9537A1329');
        $this->addSql('ALTER TABLE ticket_attachments RENAME INDEX idx_ticket_attachments_uploaded_by_id TO IDX_2B54FCA9A2B28FE8');
        $this->addSql('ALTER TABLE database_nodes RENAME INDEX idx_8b8182524600c3e3 TO IDX_622575053414710B');
        $this->addSql('ALTER TABLE shop_orders RENAME INDEX idx_shop_orders_customer_id TO IDX_608DDB6C9395C3F3');
        $this->addSql('ALTER TABLE shop_orders RENAME INDEX idx_shop_orders_product_id TO IDX_608DDB6C4584665A');
        $this->addSql('ALTER TABLE shop_orders RENAME INDEX idx_shop_orders_instance_id TO IDX_608DDB6C3A51721D');
        $this->addSql('ALTER TABLE ts3_tokens RENAME INDEX idx_ts3_tokens_server TO IDX_1041CF694BFC982F');
        $this->addSql('ALTER TABLE security_events RENAME INDEX idx_6ab7f8af4600c3e3 TO IDX_6568A15F460D9FD7');
        $this->addSql('ALTER TABLE firewall_states RENAME INDEX uniq_firewall_states_node TO UNIQ_7E779082460D9FD7');
        $this->addSql('ALTER TABLE teamspeak_update_logs RENAME INDEX idx_25bf65303f5a2c5a TO IDX_B722B55E8B35AB5C');
        $this->addSql('ALTER TABLE metric_samples RENAME INDEX idx_metric_samples_agent_id TO IDX_38A645F93414710B');
        $this->addSql('ALTER TABLE shop_rentals RENAME INDEX idx_shop_rentals_customer_id TO IDX_369F9B629395C3F3');
        $this->addSql('ALTER TABLE shop_rentals RENAME INDEX idx_shop_rentals_product_id TO IDX_369F9B624584665A');
        $this->addSql('ALTER TABLE shop_rentals RENAME INDEX uniq_shop_rentals_instance TO UNIQ_369F9B623A51721D');
        $this->addSql('ALTER TABLE webspaces RENAME INDEX idx_webspaces_customer_id TO IDX_9A008C7A9395C3F3');
        $this->addSql('ALTER TABLE webspaces RENAME INDEX idx_webspaces_node_id TO IDX_9A008C7A460D9FD7');
        $this->addSql('ALTER TABLE instance_metric_samples RENAME INDEX idx_d9719841b6bd1646 TO IDX_A7399AEA3A51721D');
        $this->addSql('ALTER TABLE domains RENAME INDEX idx_domains_customer_id TO IDX_8C7BBF9D9395C3F3');
        $this->addSql('ALTER TABLE domains RENAME INDEX idx_domains_webspace_id TO IDX_8C7BBF9DBFB01CA3');
        $this->addSql('ALTER TABLE credit_notes RENAME INDEX idx_credit_notes_invoice_id TO IDX_597428222989F1FD');
        $this->addSql('ALTER TABLE invoices RENAME INDEX idx_invoices_customer_id TO IDX_6A2F2F959395C3F3');
        $this->addSql('ALTER TABLE audit_logs RENAME INDEX idx_audit_logs_actor TO IDX_D62F285810DAF24A');
        $this->addSql('ALTER TABLE `databases` RENAME INDEX idx_databases_customer_id TO IDX_C71191C29395C3F3');
        $this->addSql('ALTER TABLE `databases` RENAME INDEX idx_7e6f5e6ea8ab0c83 TO IDX_C71191C2D376CB07');
        $this->addSql('ALTER TABLE metric_aggregates RENAME INDEX idx_2ebbb4a73414710b TO IDX_8450EB023414710B');
        $this->addSql('ALTER TABLE incident_updates RENAME INDEX idx_925c5d8eb03a8386 TO IDX_E28209EDB03A8386');
        $this->addSql('ALTER TABLE ts6_instances RENAME INDEX idx_ts6_instances_customer_id TO IDX_32034BC99395C3F3');
        $this->addSql('ALTER TABLE ts6_instances RENAME INDEX idx_ts6_instances_node_id TO IDX_32034BC9460D9FD7');
        $this->addSql('ALTER TABLE backup_targets RENAME INDEX idx_71b9a0d79395c3f3 TO IDX_1DBE33129395C3F3');
        $this->addSql('ALTER TABLE ts6_nodes RENAME INDEX fk_84edc8af3414710b TO IDX_84EDC8AF3414710B');
        $this->addSql('ALTER TABLE sinusbot_instances RENAME INDEX uniq_9f589b1b9f16e290 TO UNIQ_4213A83E3A51721D');
        $this->addSql('ALTER TABLE sinusbot_instances RENAME INDEX idx_9f589b1b460d9fd TO IDX_4213A83E460D9FD7');
        $this->addSql('ALTER TABLE ts_virtual_server RENAME INDEX idx_ts_virtual_server_customer TO IDX_DA3ADDE9395C3F3');
        $this->addSql('ALTER TABLE forum_threads RENAME INDEX fk_forum_threads_site TO IDX_9E4270ACF6BD1646');
        $this->addSql('ALTER TABLE forum_threads RENAME INDEX idx_9a57d33f97e9e282 TO IDX_9E4270ACE2544CD6');
        $this->addSql('ALTER TABLE port_allocations RENAME INDEX idx_port_allocations_instance TO IDX_B1A629A73A51721D');
        $this->addSql('ALTER TABLE port_allocations RENAME INDEX idx_port_allocations_node TO IDX_B1A629A7460D9FD7');
        $this->addSql('ALTER TABLE port_blocks RENAME INDEX idx_port_blocks_pool_id TO IDX_E9B4655E7B3406DF');
        $this->addSql('ALTER TABLE port_blocks RENAME INDEX idx_port_blocks_customer_id TO IDX_E9B4655E9395C3F3');
        $this->addSql('ALTER TABLE port_ranges RENAME INDEX idx_port_ranges_node_id TO IDX_33C44143460D9FD7');
        $this->addSql('ALTER TABLE agent_bootstrap_tokens RENAME INDEX idx_bootstrap_tokens_created_by TO IDX_8CB9A36CB03A8386');
        $this->addSql('ALTER TABLE agent_bootstrap_tokens RENAME INDEX idx_bootstrap_tokens_hash TO idx_agent_bootstrap_tokens_token_hash');
        $this->addSql('ALTER TABLE webspace_nodes RENAME INDEX idx_webspace_nodes_agent_id TO IDX_F21053CC3414710B');
        $this->addSql('ALTER TABLE mail_domains RENAME INDEX idx_mail_domains_node TO IDX_56C63EF2460D9FD7');
        $this->addSql('ALTER TABLE mail_domains RENAME INDEX idx_mail_domains_policy TO IDX_56C63EF21A12093E');
        $this->addSql('ALTER TABLE mail_domains RENAME INDEX uniq_mail_domain_domain TO uniq_mail_domain_domain_id');
        $this->addSql('ALTER TABLE sites RENAME INDEX uniq_9ebaf22b8d7673e9 TO UNIQ_BC00AA63CF2713FD');
        $this->addSql('ALTER TABLE tickets RENAME INDEX idx_tickets_customer_id TO IDX_54469DF49395C3F3');
        $this->addSql('ALTER TABLE tickets RENAME INDEX idx_tickets_assigned_to_id TO IDX_54469DF4F4BD7827');
        $this->addSql('ALTER TABLE ticket_messages RENAME INDEX idx_ticket_messages_ticket_id TO IDX_5E6BE217700047D2');
        $this->addSql('ALTER TABLE ticket_messages RENAME INDEX idx_ticket_messages_author_id TO IDX_5E6BE217F675F31B');

        // ── 5. Drop stale indexes ─────────────────────────────────────────────

        $this->addSql('DROP INDEX IDX_HP_AUDIT_TARGET ON hp_audit_log');
        $this->addSql('DROP INDEX idx_ts3_instances_status ON ts3_instances');
        $this->addSql('DROP INDEX idx_jobs_status ON jobs');
        $this->addSql('DROP INDEX IDX_MAIL_DOMAINS_DOMAIN ON mail_domains');
        $this->addSql('DROP INDEX idx_ddos_status_node ON ddos_statuses');
        $this->addSql('DROP INDEX IDX_TS6_VIRTUAL_SERVERS_CUSTOMER ON ts6_virtual_servers');
        $this->addSql('DROP INDEX IDX_TS6_VIRTUAL_SERVERS_SID ON ts6_virtual_servers');
        $this->addSql('DROP INDEX idx_gdpr_exports_token_expires ON gdpr_exports');
        $this->addSql('DROP INDEX IDX_TS3_VIRTUAL_SERVERS_SID ON ts3_virtual_servers');
        $this->addSql('DROP INDEX IDX_TS3_VIRTUAL_SERVERS_CUSTOMER ON ts3_virtual_servers');
        $this->addSql('DROP INDEX IDX_TS3_TOKENS_ACTIVE ON ts3_tokens');
        $this->addSql('DROP INDEX IDX_TS6_TOKENS_ACTIVE ON ts6_tokens');
        $this->addSql('DROP INDEX idx_instances_status ON instances');
        $this->addSql('DROP INDEX idx_consent_logs_accepted ON consent_logs');
        $this->addSql('DROP INDEX idx_consent_logs_type ON consent_logs');
        $this->addSql('DROP INDEX idx_metric_samples_recorded_at ON metric_samples');
        $this->addSql('DROP INDEX idx_metric_aggregate_bucket_start ON metric_aggregates');
        $this->addSql('DROP INDEX idx_ts6_instances_status ON ts6_instances');
        $this->addSql('DROP INDEX idx_invoices_status ON invoices');
        $this->addSql('DROP INDEX idx_credit_notes_status ON credit_notes');
        $this->addSql('DROP INDEX idx_audit_logs_created_at ON audit_logs');
        $this->addSql('DROP INDEX idx_dunning_reminders_status ON dunning_reminders');
        $this->addSql('DROP INDEX idx_ddos_policy_node ON ddos_policies');
        $this->addSql('DROP INDEX idx_port_pools_node_id ON port_pools');
        $this->addSql('DROP INDEX idx_port_ranges_protocol ON port_ranges');
        $this->addSql('DROP INDEX uniq_game_templates_key ON game_templates');
        $this->addSql('DROP INDEX idx_payments_status ON payments');
        $this->addSql('DROP INDEX idx_tickets_status ON tickets');
        $this->addSql('DROP INDEX idx_tickets_last_message_at ON tickets');
        $this->addSql('DROP INDEX idx_ts_virtual_server_status ON ts_virtual_server');
        $this->addSql('DROP INDEX IDX_REGISTRATION_TOKENS_AGENT ON agent_registration_tokens');
        $this->addSql('DROP INDEX uniq_unifi_rule_name ON unifi_port_mappings');

        // ── 6. Change column types / remove DC2Type comments ─────────────────

        $this->addSql('ALTER TABLE hp_secret CHANGE rotated_at rotated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE hp_agent CHANGE last_seen_at last_seen_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE hp_metric_sample CHANGE sampled_at sampled_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE hp_node CHANGE last_seen_at last_seen_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE hp_job_run CHANGE started_at started_at DATETIME DEFAULT NULL, CHANGE finished_at finished_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE hp_audit_log CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE game_profiles CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE ts3_instances CHANGE database_mode database_mode VARCHAR(255) NOT NULL, CHANGE status status VARCHAR(255) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE customer_profiles CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE game_template_plugins CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE instance_sftp_credentials CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE rotated_at rotated_at DATETIME DEFAULT NULL, CHANGE expires_at expires_at DATETIME DEFAULT NULL, CHANGE revealed_at revealed_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE backup_schedules CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE last_queued_at last_queued_at DATETIME DEFAULT NULL, CHANGE time_zone time_zone VARCHAR(100) NOT NULL, CHANGE compression compression VARCHAR(32) NOT NULL, CHANGE stop_before stop_before TINYINT NOT NULL, CHANGE last_run_at last_run_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE webspace_sftp_credentials CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE mailboxes CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE jobs CHANGE status status VARCHAR(255) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE locked_at locked_at DATETIME DEFAULT NULL, CHANGE lock_expires_at lock_expires_at DATETIME DEFAULT NULL, CHANGE claimed_at claimed_at DATETIME DEFAULT NULL, CHANGE last_attempt_at last_attempt_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE incidents CHANGE started_at started_at DATETIME NOT NULL, CHANGE resolved_at resolved_at DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE dns_records CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE changelog_entries CHANGE published_at published_at DATETIME NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE ticket_quick_replies CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE voice_rate_limit_states CHANGE locked_until locked_until DATETIME DEFAULT NULL, CHANGE circuit_open_until circuit_open_until DATETIME DEFAULT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE consent_logs CHANGE accepted_at accepted_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE game_definitions CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE retention_policies CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE download_items CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE scheduled_task_runs CHANGE started_at started_at DATETIME NOT NULL, CHANGE finished_at finished_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE ts3_nodes CHANGE admin_password_shown_once_at admin_password_shown_once_at DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE filetransfer_port filetransfer_port INT NOT NULL');
        $this->addSql('ALTER TABLE voice_nodes CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE backup_targets CHANGE type type VARCHAR(255) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE enabled enabled TINYINT NOT NULL');
        $this->addSql('ALTER TABLE agents CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE last_heartbeat_at last_heartbeat_at DATETIME DEFAULT NULL, CHANGE last_seen_at last_seen_at DATETIME DEFAULT NULL, CHANGE disk_scan_interval_seconds disk_scan_interval_seconds INT NOT NULL, CHANGE disk_warning_percent disk_warning_percent INT NOT NULL, CHANGE disk_hard_block_percent disk_hard_block_percent INT NOT NULL, CHANGE node_disk_protection_threshold_percent node_disk_protection_threshold_percent INT NOT NULL, CHANGE node_disk_protection_override_until node_disk_protection_override_until DATETIME DEFAULT NULL, CHANGE job_concurrency job_concurrency INT NOT NULL');
        $this->addSql('ALTER TABLE ts3_virtual_servers RENAME INDEX idx_ts3_virtual_servers_node TO IDX_EAB55613460D9FD7');
        $this->addSql('ALTER TABLE ts3_virtual_servers CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE archived_at archived_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE gdpr_exports CHANGE requested_at requested_at DATETIME NOT NULL, CHANGE ready_at ready_at DATETIME DEFAULT NULL, CHANGE expires_at expires_at DATETIME NOT NULL, CHANGE download_token_expires_at download_token_expires_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE shop_orders CHANGE type type VARCHAR(255) NOT NULL, CHANGE status status VARCHAR(255) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE ts3_tokens CHANGE created_at created_at DATETIME NOT NULL, CHANGE revoked_at revoked_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_templates CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE security_events CHANGE occurred_at occurred_at DATETIME NOT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE firewall_states CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE status_components CHANGE last_checked_at last_checked_at DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE metric_samples CHANGE recorded_at recorded_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE mail_aliases CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE cms_pages CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE cms_blocks CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE version version INT NOT NULL');
        $this->addSql('ALTER TABLE minecraft_versions_catalog CHANGE released_at released_at DATETIME DEFAULT NULL, CHANGE source source VARCHAR(16) DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE backup_definitions CHANGE target_type target_type VARCHAR(255) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE notifications CHANGE read_at read_at DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE job_results CHANGE status status VARCHAR(255) NOT NULL, CHANGE completed_at completed_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE users CHANGE created_at created_at DATETIME NOT NULL, CHANGE email_verified_at email_verified_at DATETIME DEFAULT NULL, CHANGE email_verification_expires_at email_verification_expires_at DATETIME DEFAULT NULL, CHANGE terms_accepted_at terms_accepted_at DATETIME DEFAULT NULL, CHANGE privacy_accepted_at privacy_accepted_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE ts6_virtual_servers RENAME INDEX idx_ts6_virtual_servers_node TO IDX_1E1BBA9F460D9FD7');
        $this->addSql('ALTER TABLE ts6_virtual_servers CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE archived_at archived_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE abuse_log CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE voice_instances CHANGE checked_at checked_at DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE blog_tags CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE forum_categories CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE tenants CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE agent_registration_tokens CHANGE created_at created_at DATETIME NOT NULL, CHANGE expires_at expires_at DATETIME NOT NULL, CHANGE used_at used_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE payments CHANGE status status VARCHAR(255) NOT NULL, CHANGE received_at received_at DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE config_schemas CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE log_indices CHANGE last_indexed_at last_indexed_at DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE invoice_archives CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE ddos_provider_credentials CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE ddos_statuses CHANGE reported_at reported_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE port_stats port_stats JSON NOT NULL');
        $this->addSql('ALTER TABLE dunning_reminders CHANGE status status VARCHAR(255) NOT NULL, CHANGE sent_at sent_at DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE ts6_tokens CHANGE created_at created_at DATETIME NOT NULL, CHANGE revoked_at revoked_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE instances CHANGE status status VARCHAR(255) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE update_policy update_policy VARCHAR(255) NOT NULL, CHANGE last_update_queued_at last_update_queued_at DATETIME DEFAULT NULL, CHANGE max_slots max_slots INT NOT NULL, CHANGE current_slots current_slots INT NOT NULL, CHANGE lock_slots lock_slots TINYINT NOT NULL, CHANGE query_status_cache query_status_cache JSON NOT NULL, CHANGE query_checked_at query_checked_at DATETIME DEFAULT NULL, CHANGE disk_used_bytes disk_used_bytes BIGINT NOT NULL, CHANGE disk_state disk_state VARCHAR(255) NOT NULL, CHANGE disk_last_scanned_at disk_last_scanned_at DATETIME DEFAULT NULL, CHANGE slots slots INT NOT NULL');
        $this->addSql('ALTER TABLE instance_schedules CHANGE action action VARCHAR(255) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE last_queued_at last_queued_at DATETIME DEFAULT NULL, CHANGE last_run_at last_run_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE api_tokens CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE last_used_at last_used_at DATETIME DEFAULT NULL, CHANGE expires_at expires_at DATETIME DEFAULT NULL, CHANGE revoked_at revoked_at DATETIME DEFAULT NULL, CHANGE rotated_at rotated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE public_servers CHANGE next_check_at next_check_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE ts6_viewers CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE security_policy_revisions CHANGE created_at created_at DATETIME NOT NULL, CHANGE applied_at applied_at DATETIME DEFAULT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE forum_posts CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE is_deleted is_deleted TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE users CHANGE created_at created_at DATETIME NOT NULL, CHANGE email_verified_at email_verified_at DATETIME DEFAULT NULL, CHANGE email_verification_expires_at email_verification_expires_at DATETIME DEFAULT NULL, CHANGE terms_accepted_at terms_accepted_at DATETIME DEFAULT NULL, CHANGE privacy_accepted_at privacy_accepted_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE server_sftp_access CHANGE enabled enabled TINYINT DEFAULT 0 NOT NULL, CHANGE password_set_at password_set_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE game_templates CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE app_settings CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE forum_member_bans CHANGE banned_until banned_until DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_messages CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE maintenance_windows CHANGE start_at start_at DATETIME NOT NULL, CHANGE end_at end_at DATETIME NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE webspace_nodes CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE ts6_nodes CHANGE admin_password_shown_once_at admin_password_shown_once_at DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE voice_port voice_port INT NOT NULL');
        $this->addSql('ALTER TABLE knowledge_base_articles CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE forum_boards CHANGE is_active is_active TINYINT DEFAULT 1 NOT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE invoices CHANGE status status VARCHAR(255) NOT NULL, CHANGE due_date due_date DATETIME NOT NULL, CHANGE paid_at paid_at DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE agent_bootstrap_tokens CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE used_at used_at DATETIME DEFAULT NULL, CHANGE revoked_at revoked_at DATETIME DEFAULT NULL, CHANGE attempts_count attempts_count INT NOT NULL, CHANGE max_attempts max_attempts INT NOT NULL');
        $this->addSql('ALTER TABLE user_sessions CHANGE created_at created_at DATETIME NOT NULL, CHANGE last_used_at last_used_at DATETIME DEFAULT NULL, CHANGE expires_at expires_at DATETIME DEFAULT NULL, CHANGE revoked_at revoked_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE shop_rentals CHANGE expires_at expires_at DATETIME NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE webspaces CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE status status VARCHAR(20) NOT NULL, CHANGE docroot docroot VARCHAR(255) NOT NULL, CHANGE disk_limit_bytes disk_limit_bytes INT NOT NULL, CHANGE ftp_enabled ftp_enabled TINYINT NOT NULL, CHANGE sftp_enabled sftp_enabled TINYINT NOT NULL, CHANGE system_username system_username VARCHAR(64) NOT NULL, CHANGE deleted_at deleted_at DATETIME DEFAULT NULL, CHANGE domain domain VARCHAR(255) NOT NULL, CHANGE ddos_protection_enabled ddos_protection_enabled TINYINT NOT NULL, CHANGE last_applied_at last_applied_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE mail_nodes CHANGE roundcube_url roundcube_url VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE job_logs CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE instance_metric_samples CHANGE collected_at collected_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE module_settings CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE ddos_policies CHANGE applied_at applied_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE invoice_preferences CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE portal_language portal_language VARCHAR(5) NOT NULL');
        $this->addSql('ALTER TABLE blog_categories CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE domains CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE ssl_expires_at ssl_expires_at DATETIME DEFAULT NULL, CHANGE last_applied_at last_applied_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE media_assets CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE backups CHANGE status status VARCHAR(255) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE completed_at completed_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE incident_updates CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE ts3_viewers CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE ticket_attachments CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE database_nodes CHANGE last_checked_at last_checked_at DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE gdpr_deletion_requests CHANGE requested_at requested_at DATETIME NOT NULL, CHANGE processed_at processed_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE cms_events CHANGE start_at start_at DATETIME NOT NULL, CHANGE end_at end_at DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE tickets CHANGE category category VARCHAR(255) NOT NULL, CHANGE status status VARCHAR(255) NOT NULL, CHANGE priority priority VARCHAR(255) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE last_message_at last_message_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE port_allocations CHANGE last_checked_at last_checked_at DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE port_blocks CHANGE assigned_at assigned_at DATETIME DEFAULT NULL, CHANGE released_at released_at DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE port_pools CHANGE tag tag VARCHAR(120) NOT NULL, CHANGE enabled enabled TINYINT NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE port_ranges CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE unifi_manual_rules CHANGE description description LONGTEXT DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE unifi_audit_log CHANGE error error LONGTEXT DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE unifi_policy CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE unifi_port_mappings CHANGE last_error last_error LONGTEXT DEFAULT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE unifi_settings CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE sites CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE maintenance_starts_at maintenance_starts_at DATETIME DEFAULT NULL, CHANGE maintenance_ends_at maintenance_ends_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE sinusbot_nodes CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE team_members CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE cms_site_settings CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE cms_posts CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE credit_notes CHANGE status status VARCHAR(255) NOT NULL, CHANGE issued_at issued_at DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE audit_logs CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE `databases` CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE rotated_at rotated_at DATETIME DEFAULT NULL, CHANGE expires_at expires_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE ts6_instances CHANGE status status VARCHAR(255) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');

        // ── 7. Add new columns to existing tables ────────────────────────────

        $this->addSql('ALTER TABLE sinusbot_nodes ADD running TINYINT NOT NULL, ADD customer_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE team_members ADD team_name VARCHAR(140) DEFAULT NULL');
        $this->addSql('ALTER TABLE forum_posts ADD deleted_at DATETIME DEFAULT NULL, ADD deleted_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cms_posts ADD seo_title VARCHAR(180) DEFAULT NULL, ADD seo_description LONGTEXT DEFAULT NULL, ADD featured_image_path VARCHAR(255) DEFAULT NULL, ADD category_id INT DEFAULT NULL, CHANGE published_at published_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE sinusbot_instances ADD bot_id VARCHAR(64) DEFAULT NULL, ADD web_port INT DEFAULT NULL, ADD last_seen_at DATETIME DEFAULT NULL');

        $this->addSql('ALTER TABLE sites ADD cms_webspace_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cms_site_settings ADD header_links_json JSON DEFAULT NULL, ADD footer_links_json JSON DEFAULT NULL, ADD impressum_content LONGTEXT DEFAULT NULL, ADD datenschutz_content LONGTEXT DEFAULT NULL');

        // mail_domains: add new columns, drop old ones, rename one
        $this->addSql('ALTER TABLE mail_domains ADD domain VARCHAR(253) NOT NULL DEFAULT \'\', ADD dkim_status VARCHAR(16) NOT NULL DEFAULT \'\', ADD spf_status VARCHAR(16) NOT NULL DEFAULT \'\', ADD dmarc_status VARCHAR(16) NOT NULL DEFAULT \'\', ADD mx_status VARCHAR(16) NOT NULL DEFAULT \'\', ADD tls_status VARCHAR(16) NOT NULL DEFAULT \'\', ADD mail_enabled TINYINT DEFAULT 1 NOT NULL, ADD created_at DATETIME NOT NULL DEFAULT \'2000-01-01 00:00:00\', ADD updated_at DATETIME NOT NULL DEFAULT \'2000-01-01 00:00:00\', ADD owner_id INT NOT NULL DEFAULT 0, DROP dkim_private_key_payload, DROP dkim_previous_private_key_payload, CHANGE dkim_rotated_at dns_last_checked_at DATETIME DEFAULT NULL');
        // Remove temp defaults
        $this->addSql('ALTER TABLE mail_domains ALTER domain DROP DEFAULT, ALTER dkim_status DROP DEFAULT, ALTER spf_status DROP DEFAULT, ALTER dmarc_status DROP DEFAULT, ALTER mx_status DROP DEFAULT, ALTER tls_status DROP DEFAULT, ALTER created_at DROP DEFAULT, ALTER updated_at DROP DEFAULT, ALTER owner_id DROP DEFAULT');

        // ts_virtual_server: rename ts6_instance_id -> instance_id
        $this->addSql('ALTER TABLE ts_virtual_server DROP FOREIGN KEY `FK_TS_VIRTUAL_SERVER_INSTANCE`');
        $this->addSql('DROP INDEX idx_ts_virtual_server_instance ON ts_virtual_server');
        $this->addSql('ALTER TABLE ts_virtual_server CHANGE status status VARCHAR(255) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE ts6_instance_id instance_id INT NOT NULL');

        // forum_threads: add last_activity_at
        $this->addSql('ALTER TABLE forum_threads ADD last_activity_at DATETIME NOT NULL DEFAULT \'2000-01-01 00:00:00\', CHANGE is_pinned is_pinned TINYINT DEFAULT 0 NOT NULL, CHANGE is_closed is_closed TINYINT DEFAULT 0 NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE last_post_at last_post_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE forum_threads ALTER last_activity_at DROP DEFAULT');

        // ── 8. Drop FK constraints before dropping old columns ────────────────

        $this->addSql('ALTER TABLE shop_products DROP FOREIGN KEY `FK_SHOP_PRODUCTS_SITE`');
        $this->addSql('DROP INDEX idx_shop_products_site_id ON shop_products');
        $this->addSql('ALTER TABLE shop_products CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE is_public_active is_public_active TINYINT NOT NULL, CHANGE is_customer_active is_customer_active TINYINT NOT NULL');

        $this->addSql('ALTER TABLE shop_categories DROP FOREIGN KEY `FK_SHOP_CATEGORIES_SITE`');
        $this->addSql('DROP INDEX idx_shop_categories_site_id ON shop_categories');
        $this->addSql('ALTER TABLE shop_categories CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');

        $this->addSql('ALTER TABLE sinusbot_instances DROP FOREIGN KEY `FK_9F589B1B460D9FD`');
        $this->addSql('ALTER TABLE sinusbot_instances CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE archived_at archived_at DATETIME DEFAULT NULL');

        $this->addSql('ALTER TABLE backup_targets DROP FOREIGN KEY `FK_71B9A0D79395C3F3`');
        $this->addSql('ALTER TABLE backup_targets ADD CONSTRAINT FK_1DBE33129395C3F3 FOREIGN KEY (customer_id) REFERENCES users (id)');

        $this->addSql('ALTER TABLE metric_aggregates DROP FOREIGN KEY `FK_2EBBB4A73414710B`');
        $this->addSql('ALTER TABLE metric_aggregates CHANGE bucket_start bucket_start DATETIME NOT NULL');
        $this->addSql('ALTER TABLE metric_aggregates ADD CONSTRAINT FK_8450EB023414710B FOREIGN KEY (agent_id) REFERENCES agents (id)');

        $this->addSql('ALTER TABLE port_allocations DROP FOREIGN KEY `fk_port_allocations_instance`');
        $this->addSql('ALTER TABLE port_allocations ADD CONSTRAINT FK_B1A629A73A51721D FOREIGN KEY (instance_id) REFERENCES instances (id)');

        // ── 9. Add new FK constraints to existing tables ─────────────────────

        $this->addSql('ALTER TABLE sinusbot_nodes ADD CONSTRAINT FK_D805E71D9395C3F3 FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_D805E71D9395C3F3 ON sinusbot_nodes (customer_id)');

        $this->addSql('ALTER TABLE sites ADD CONSTRAINT FK_BC00AA632251AB77 FOREIGN KEY (cms_webspace_id) REFERENCES webspaces (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_BC00AA632251AB77 ON sites (cms_webspace_id)');

        $this->addSql('ALTER TABLE voice_rate_limit_states ADD CONSTRAINT FK_4B1DB3A2460D9FD7 FOREIGN KEY (node_id) REFERENCES voice_nodes (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE forum_posts ADD CONSTRAINT FK_90291C2DC76F1F52 FOREIGN KEY (deleted_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_90291C2DC76F1F52 ON forum_posts (deleted_by_id)');

        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E991E6A19D FOREIGN KEY (reseller_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_users_reseller ON users (reseller_id)');

        $this->addSql('ALTER TABLE cms_posts ADD CONSTRAINT FK_A62E21D612469DE2 FOREIGN KEY (category_id) REFERENCES blog_categories (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_A62E21D612469DE2 ON cms_posts (category_id)');

        $this->addSql('ALTER TABLE sinusbot_instances ADD CONSTRAINT FK_4213A83E9395C3F3 FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE voice_instances ADD CONSTRAINT FK_A24AB509395C3F3 FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE voice_instances ADD CONSTRAINT FK_A24AB50460D9FD7 FOREIGN KEY (node_id) REFERENCES voice_nodes (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE mail_domains ADD CONSTRAINT FK_56C63EF27E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_56C63EF27E3C61F9 ON mail_domains (owner_id)');
        $this->addSql('CREATE INDEX idx_mail_domains_statuses ON mail_domains (dkim_status, spf_status, dmarc_status, mx_status, tls_status)');
        $this->addSql('CREATE INDEX idx_mail_domains_dns_last_checked ON mail_domains (dns_last_checked_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_mail_domain_owner_domain ON mail_domains (owner_id, domain)');

        $this->addSql('ALTER TABLE ts_virtual_server ADD CONSTRAINT FK_DA3ADDE3A51721D FOREIGN KEY (instance_id) REFERENCES ts6_instances (id)');
        $this->addSql('CREATE INDEX IDX_DA3ADDE3A51721D ON ts_virtual_server (instance_id)');

        $this->addSql('CREATE INDEX idx_forum_threads_board_activity ON forum_threads (board_id, last_activity_at)');

        $this->addSql('ALTER TABLE webspace_nodes ADD CONSTRAINT FK_F21053CC3414710B FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE port_ranges ADD CONSTRAINT FK_33C44143460D9FD7 FOREIGN KEY (node_id) REFERENCES agents (id)');

        $this->addSql('ALTER TABLE team_groups ADD CONSTRAINT FK_86767EA9F6BD1646 FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE');

        // ── 10. port_blocks: change regular index to unique ───────────────────

        $this->addSql('ALTER TABLE port_blocks DROP INDEX idx_port_blocks_instance_id, ADD UNIQUE INDEX UNIQ_E9B4655E3A51721D (instance_id)');
    }

    public function down(Schema $schema): void
    {
        // Drop the new tables created in up()
        $newTables = [
            'mail_rate_limits', 'mail_logs', 'mail_dkim_keys', 'mail_forwardings',
            'webspace_certificates', 'cms_template_versions', 'blog_post_tags',
            'mail_users', 'webspace_vhosts', 'mail_policies', 'forum_post_reports',
            'mail_metric_buckets', 'messenger_messages', 'cms_templates',
        ];
        foreach ($newTables as $table) {
            if ($schema->hasTable($table)) {
                $this->addSql('DROP TABLE ' . $table);
            }
        }
    }
}
