<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20261015160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Squashed mail control-plane migration: schema foundation, mail_domains aggregate, logs analytics, policies, rate counter extensions and metric buckets.';
    }

    public function up(Schema $schema): void
    {
        // This migration contains PostgreSQL-only DDL (SERIAL, TIMESTAMP WITHOUT TIME ZONE,
        // JSONB, NOT DEFERRABLE, UPDATE...FROM). Skip entirely on MySQL/MariaDB;
        // the MySQL mail infrastructure is managed by the Version20261015090000 family.
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->write('Skipping PostgreSQL-only mail control-plane migration on MySQL/MariaDB.');
            return;
        }

        if (!$schema->hasTable('mail_users')) {
            $this->addSql('CREATE TABLE mail_users (id SERIAL NOT NULL, mailbox_id INT NOT NULL, customer_id INT NOT NULL, domain_id INT NOT NULL, local_part VARCHAR(190) NOT NULL, address VARCHAR(255) NOT NULL, password_hash VARCHAR(255) NOT NULL, quota_mb INT NOT NULL, enabled BOOLEAN NOT NULL DEFAULT TRUE, last_auth_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_auth_ip VARCHAR(45) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE UNIQUE INDEX uniq_mail_users_address ON mail_users (address)');
            $this->addSql('CREATE UNIQUE INDEX uniq_mail_users_mailbox ON mail_users (mailbox_id)');
            $this->addSql('CREATE INDEX idx_mail_users_domain_enabled ON mail_users (domain_id, enabled)');
            $this->addSql('CREATE INDEX idx_mail_users_customer ON mail_users (customer_id)');
            $this->addSql('ALTER TABLE mail_users ADD CONSTRAINT fk_mail_users_mailbox FOREIGN KEY (mailbox_id) REFERENCES mailboxes (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE mail_users ADD CONSTRAINT fk_mail_users_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE mail_users ADD CONSTRAINT fk_mail_users_domain FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        }

        if (!$schema->hasTable('mail_forwardings')) {
            $this->addSql('CREATE TABLE mail_forwardings (id SERIAL NOT NULL, customer_id INT NOT NULL, domain_id INT NOT NULL, source_local_part VARCHAR(190) NOT NULL, destination VARCHAR(255) NOT NULL, enabled BOOLEAN NOT NULL DEFAULT TRUE, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE UNIQUE INDEX uniq_mail_forwarding_route ON mail_forwardings (domain_id, source_local_part, destination)');
            $this->addSql('CREATE INDEX idx_mail_forwardings_domain_enabled ON mail_forwardings (domain_id, enabled)');
            $this->addSql('CREATE INDEX idx_mail_forwardings_customer ON mail_forwardings (customer_id)');
            $this->addSql('ALTER TABLE mail_forwardings ADD CONSTRAINT fk_mail_forwardings_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE mail_forwardings ADD CONSTRAINT fk_mail_forwardings_domain FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        }

        if (!$schema->hasTable('mail_logs')) {
            $this->addSql("CREATE TABLE mail_logs (id BIGSERIAL NOT NULL, domain_id INT NOT NULL, event_type VARCHAR(32) NOT NULL, level VARCHAR(16) NOT NULL DEFAULT 'info', source VARCHAR(32) NOT NULL DEFAULT 'agent', message TEXT NOT NULL DEFAULT '', user_id INT DEFAULT NULL, payload JSONB NOT NULL DEFAULT '{}'::jsonb, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
            $this->addSql('ALTER TABLE mail_logs ADD CONSTRAINT fk_mail_logs_domain FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE mail_logs ADD CONSTRAINT fk_mail_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        } else {
            $this->addSql("ALTER TABLE mail_logs ADD COLUMN IF NOT EXISTS level VARCHAR(16) NOT NULL DEFAULT 'info'");
            $this->addSql("ALTER TABLE mail_logs ADD COLUMN IF NOT EXISTS source VARCHAR(32) NOT NULL DEFAULT 'agent'");
            $this->addSql("ALTER TABLE mail_logs ADD COLUMN IF NOT EXISTS message TEXT NOT NULL DEFAULT ''");
            $this->addSql("ALTER TABLE mail_logs ADD COLUMN IF NOT EXISTS user_id INT DEFAULT NULL");
            $this->addSql("ALTER TABLE mail_logs ADD COLUMN IF NOT EXISTS payload JSONB NOT NULL DEFAULT '{}'::jsonb");
            $this->addSql('ALTER TABLE mail_logs ADD CONSTRAINT IF NOT EXISTS fk_mail_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        }

        $this->addSql("ALTER TABLE mail_logs ADD CONSTRAINT IF NOT EXISTS chk_mail_logs_level CHECK (level IN ('info','warning','error','critical'))");
        $this->addSql("ALTER TABLE mail_logs ADD CONSTRAINT IF NOT EXISTS chk_mail_logs_source CHECK (source IN ('postfix','dovecot','opendkim','agent','dns','rspamd'))");
        $this->addSql("ALTER TABLE mail_logs ADD CONSTRAINT IF NOT EXISTS chk_mail_logs_event_type CHECK (event_type IN ('delivery','auth','tls','spam','bounce','dns_check','queue','policy'))");
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mail_logs_created_at ON mail_logs (created_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mail_logs_domain_id_created_at ON mail_logs (domain_id, created_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mail_logs_level_created_at ON mail_logs (level, created_at)');

        if (!$schema->hasTable('mail_rate_limits')) {
            $this->addSql("CREATE TABLE mail_rate_limits (id SERIAL NOT NULL, customer_id INT NOT NULL, mailbox_id INT NOT NULL, max_mails_per_hour INT NOT NULL DEFAULT 240, max_recipients_per_mail INT NOT NULL DEFAULT 100, burst_per_minute INT NOT NULL DEFAULT 40, greylisting_enabled BOOLEAN NOT NULL DEFAULT FALSE, tls_only BOOLEAN NOT NULL DEFAULT FALSE, strict_spf_dkim BOOLEAN NOT NULL DEFAULT TRUE, dmarc_policy VARCHAR(16) NOT NULL DEFAULT 'quarantine', counter_window_start TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(), current_count INT NOT NULL DEFAULT 0, blocked_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
            $this->addSql('CREATE UNIQUE INDEX uniq_mail_rate_limits_mailbox ON mail_rate_limits (mailbox_id)');
            $this->addSql('CREATE INDEX idx_mail_rate_limits_customer ON mail_rate_limits (customer_id)');
            $this->addSql('ALTER TABLE mail_rate_limits ADD CONSTRAINT fk_mail_rate_limits_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE mail_rate_limits ADD CONSTRAINT fk_mail_rate_limits_mailbox FOREIGN KEY (mailbox_id) REFERENCES mailboxes (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        } else {
            $this->addSql('ALTER TABLE mail_rate_limits ADD COLUMN IF NOT EXISTS counter_window_start TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()');
            $this->addSql('ALTER TABLE mail_rate_limits ADD COLUMN IF NOT EXISTS current_count INT NOT NULL DEFAULT 0');
            $this->addSql('ALTER TABLE mail_rate_limits ADD COLUMN IF NOT EXISTS blocked_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        }

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mail_rate_limits_counter_window ON mail_rate_limits (counter_window_start)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mail_rate_limits_blocked_until ON mail_rate_limits (blocked_until)');
        $this->addSql('ALTER TABLE mail_rate_limits ADD CONSTRAINT IF NOT EXISTS chk_mail_rate_limits_current_count_non_negative CHECK (current_count >= 0)');

        if (!$schema->hasTable('mail_dkim_keys')) {
            $this->addSql("CREATE TABLE mail_dkim_keys (id SERIAL NOT NULL, customer_id INT NOT NULL, domain_id INT NOT NULL, selector VARCHAR(64) NOT NULL, private_key_path VARCHAR(255) NOT NULL, public_key TEXT NOT NULL, dns_value TEXT NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'active', created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, rotated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))");
            $this->addSql('CREATE INDEX idx_mail_dkim_keys_domain_status ON mail_dkim_keys (domain_id, status)');
            $this->addSql('CREATE INDEX idx_mail_dkim_keys_customer ON mail_dkim_keys (customer_id)');
            $this->addSql('CREATE UNIQUE INDEX uniq_mail_dkim_selector_domain ON mail_dkim_keys (domain_id, selector)');
            $this->addSql('ALTER TABLE mail_dkim_keys ADD CONSTRAINT fk_mail_dkim_keys_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE mail_dkim_keys ADD CONSTRAINT fk_mail_dkim_keys_domain FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        }

        if ($schema->hasTable('mail_domains')) {
            $table = $schema->getTable('mail_domains');
            if (!$table->hasColumn('owner_id')) {
                $this->addSql('ALTER TABLE mail_domains ADD owner_id INT DEFAULT NULL');
            }
            if (!$table->hasColumn('domain')) {
                $this->addSql("ALTER TABLE mail_domains ADD domain VARCHAR(253) DEFAULT ''");
            }
            if (!$table->hasColumn('dkim_status')) {
                $this->addSql("ALTER TABLE mail_domains ADD dkim_status VARCHAR(16) NOT NULL DEFAULT 'unknown'");
            }
            if (!$table->hasColumn('spf_status')) {
                $this->addSql("ALTER TABLE mail_domains ADD spf_status VARCHAR(16) NOT NULL DEFAULT 'unknown'");
            }
            if (!$table->hasColumn('dmarc_status')) {
                $this->addSql("ALTER TABLE mail_domains ADD dmarc_status VARCHAR(16) NOT NULL DEFAULT 'unknown'");
            }
            if (!$table->hasColumn('mx_status')) {
                $this->addSql("ALTER TABLE mail_domains ADD mx_status VARCHAR(16) NOT NULL DEFAULT 'unknown'");
            }
            if (!$table->hasColumn('tls_status')) {
                $this->addSql("ALTER TABLE mail_domains ADD tls_status VARCHAR(16) NOT NULL DEFAULT 'unknown'");
            }
            if (!$table->hasColumn('dns_last_checked_at')) {
                $this->addSql('ALTER TABLE mail_domains ADD dns_last_checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
            }
            if (!$table->hasColumn('mail_enabled')) {
                $this->addSql('ALTER TABLE mail_domains ADD mail_enabled BOOLEAN NOT NULL DEFAULT TRUE');
            }
            if (!$table->hasColumn('created_at')) {
                $this->addSql('ALTER TABLE mail_domains ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NOW()');
            }
            if (!$table->hasColumn('updated_at')) {
                $this->addSql('ALTER TABLE mail_domains ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NOW()');
            }

            $this->addSql('UPDATE mail_domains md SET owner_id = d.customer_id, domain = LOWER(d.name) FROM domains d WHERE md.domain_id = d.id');
            $this->addSql('UPDATE mail_domains SET domain = LOWER(domain) WHERE domain <> LOWER(domain)');
            $this->addSql('ALTER TABLE mail_domains ALTER COLUMN owner_id SET NOT NULL');
            $this->addSql('ALTER TABLE mail_domains ALTER COLUMN domain SET NOT NULL');

            $this->addSql('ALTER TABLE mail_domains ADD CONSTRAINT IF NOT EXISTS fk_mail_domains_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_mail_domain_owner_domain ON mail_domains (owner_id, domain)');
            $this->addSql('CREATE INDEX IF NOT EXISTS idx_mail_domains_owner_domain ON mail_domains (owner_id, domain)');
            $this->addSql('CREATE INDEX IF NOT EXISTS idx_mail_domains_statuses ON mail_domains (dkim_status, spf_status, dmarc_status, mx_status, tls_status)');
            $this->addSql('CREATE INDEX IF NOT EXISTS idx_mail_domains_dns_last_checked ON mail_domains (dns_last_checked_at)');
            $this->addSql("ALTER TABLE mail_domains ADD CONSTRAINT IF NOT EXISTS chk_mail_domains_dkim_status CHECK (dkim_status IN ('unknown','ok','warning','error'))");
            $this->addSql("ALTER TABLE mail_domains ADD CONSTRAINT IF NOT EXISTS chk_mail_domains_spf_status CHECK (spf_status IN ('unknown','ok','warning','error'))");
            $this->addSql("ALTER TABLE mail_domains ADD CONSTRAINT IF NOT EXISTS chk_mail_domains_dmarc_status CHECK (dmarc_status IN ('unknown','ok','warning','error'))");
            $this->addSql("ALTER TABLE mail_domains ADD CONSTRAINT IF NOT EXISTS chk_mail_domains_mx_status CHECK (mx_status IN ('unknown','ok','warning','error'))");
            $this->addSql("ALTER TABLE mail_domains ADD CONSTRAINT IF NOT EXISTS chk_mail_domains_tls_status CHECK (tls_status IN ('unknown','ok','warning','error'))");
        }

        if (!$schema->hasTable('mail_policies')) {
            $this->addSql("CREATE TABLE mail_policies (id SERIAL NOT NULL, owner_id INT NOT NULL, domain_id INT NOT NULL, require_tls BOOLEAN NOT NULL DEFAULT FALSE, max_recipients INT NOT NULL DEFAULT 100, max_hourly_emails INT NOT NULL DEFAULT 500, allow_external_forwarding BOOLEAN NOT NULL DEFAULT FALSE, spam_protection_level VARCHAR(8) NOT NULL DEFAULT 'med', greylisting_enabled BOOLEAN NOT NULL DEFAULT TRUE, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
            $this->addSql('CREATE UNIQUE INDEX uniq_mail_policy_domain ON mail_policies (domain_id)');
            $this->addSql('CREATE INDEX idx_mail_policy_owner_domain ON mail_policies (owner_id, domain_id)');
            $this->addSql("ALTER TABLE mail_policies ADD CONSTRAINT chk_mail_policy_spam_level CHECK (spam_protection_level IN ('low','med','high'))");
            $this->addSql('ALTER TABLE mail_policies ADD CONSTRAINT chk_mail_policy_max_recipients CHECK (max_recipients BETWEEN 1 AND 1000)');
            $this->addSql('ALTER TABLE mail_policies ADD CONSTRAINT chk_mail_policy_max_hourly CHECK (max_hourly_emails BETWEEN 1 AND 100000)');
            $this->addSql('ALTER TABLE mail_policies ADD CONSTRAINT fk_mail_policy_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE mail_policies ADD CONSTRAINT fk_mail_policy_domain FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        }

        $this->addSql('CREATE TABLE IF NOT EXISTS mail_metric_buckets (
            id BIGSERIAL NOT NULL,
            domain_id BIGINT DEFAULT NULL,
            bucket_start TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            bucket_size_seconds INT NOT NULL,
            metric_name VARCHAR(64) NOT NULL,
            metric_value DOUBLE PRECISION NOT NULL,
            dimensions JSONB NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mail_metric_buckets_bucket ON mail_metric_buckets (bucket_start, bucket_size_seconds)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mail_metric_buckets_metric ON mail_metric_buckets (metric_name, bucket_start)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mail_metric_buckets_domain_metric ON mail_metric_buckets (domain_id, metric_name, bucket_start)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mail_metric_buckets_dimensions_gin ON mail_metric_buckets USING GIN (dimensions)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_mail_metric_bucket_rollup ON mail_metric_buckets (domain_id, bucket_start, bucket_size_seconds, metric_name, md5(dimensions::text))');
        $this->addSql('ALTER TABLE mail_metric_buckets ADD CONSTRAINT IF NOT EXISTS FK_MAIL_METRIC_BUCKETS_DOMAIN FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // Intentionally irreversible squash migration.
    }
}
