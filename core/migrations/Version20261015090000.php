<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20261015090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mail hosting platform entities (mail nodes/domains/policies/forwards).';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('mail_nodes')) {
            $this->addSql('CREATE TABLE mail_nodes (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, imap_host VARCHAR(255) NOT NULL, imap_port INT NOT NULL, smtp_host VARCHAR(255) NOT NULL, smtp_port INT NOT NULL, roundcube_url VARCHAR(255) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$schema->hasTable('quota_policies')) {
            $this->addSql('CREATE TABLE quota_policies (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, max_accounts INT NOT NULL, max_domain_quota_mb INT NOT NULL, max_mailbox_quota_mb INT NOT NULL, UNIQUE INDEX UNIQ_QUOTA_POLICY_NAME (name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$schema->hasTable('mail_domains')) {
            $this->addSql('CREATE TABLE mail_domains (id INT AUTO_INCREMENT NOT NULL, domain_id INT NOT NULL, node_id INT NOT NULL, quota_policy_id INT DEFAULT NULL, dkim_selector VARCHAR(64) NOT NULL, dmarc_policy VARCHAR(16) NOT NULL, INDEX IDX_MAIL_DOMAINS_DOMAIN (domain_id), INDEX IDX_MAIL_DOMAINS_NODE (node_id), INDEX IDX_MAIL_DOMAINS_POLICY (quota_policy_id), UNIQUE INDEX UNIQ_MAIL_DOMAIN_DOMAIN (domain_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE mail_domains ADD CONSTRAINT FK_MAIL_DOMAINS_DOMAIN FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE mail_domains ADD CONSTRAINT FK_MAIL_DOMAINS_NODE FOREIGN KEY (node_id) REFERENCES mail_nodes (id)');
            $this->addSql('ALTER TABLE mail_domains ADD CONSTRAINT FK_MAIL_DOMAINS_POLICY FOREIGN KEY (quota_policy_id) REFERENCES quota_policies (id)');
        }

        if (!$schema->hasTable('mail_forwards')) {
            $this->addSql('CREATE TABLE mail_forwards (id INT AUTO_INCREMENT NOT NULL, domain_id INT NOT NULL, source_local_part VARCHAR(190) NOT NULL, destination VARCHAR(255) NOT NULL, enabled TINYINT(1) NOT NULL, INDEX IDX_MAIL_FORWARDS_DOMAIN (domain_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE mail_forwards ADD CONSTRAINT FK_MAIL_FORWARDS_DOMAIN FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('mail_forwards')) {
            $this->addSql('ALTER TABLE mail_forwards DROP FOREIGN KEY FK_MAIL_FORWARDS_DOMAIN');
            $this->addSql('DROP TABLE mail_forwards');
        }
        if ($schema->hasTable('mail_domains')) {
            $this->addSql('ALTER TABLE mail_domains DROP FOREIGN KEY FK_MAIL_DOMAINS_DOMAIN');
            $this->addSql('ALTER TABLE mail_domains DROP FOREIGN KEY FK_MAIL_DOMAINS_NODE');
            $this->addSql('ALTER TABLE mail_domains DROP FOREIGN KEY FK_MAIL_DOMAINS_POLICY');
            $this->addSql('DROP TABLE mail_domains');
        }
        if ($schema->hasTable('quota_policies')) {
            $this->addSql('DROP TABLE quota_policies');
        }
        if ($schema->hasTable('mail_nodes')) {
            $this->addSql('DROP TABLE mail_nodes');
        }
    }
}
