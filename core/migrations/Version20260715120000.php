<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260715120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add security policy revisions and security events.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('security_policy_revisions')) {
            $this->addSql('CREATE TABLE security_policy_revisions (id INT AUTO_INCREMENT NOT NULL, node_id VARCHAR(64) NOT NULL, created_by_id INT DEFAULT NULL, policy_type VARCHAR(32) NOT NULL, version INT NOT NULL, payload JSON NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', applied_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', checksum VARCHAR(64) NOT NULL, INDEX idx_security_policy_node_type (node_id, policy_type), UNIQUE INDEX uniq_security_policy_version (node_id, policy_type, version), INDEX IDX_E9F1D0BD4600C3E3 (node_id), INDEX IDX_E9F1D0BDB03A8386 (created_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE security_policy_revisions ADD CONSTRAINT FK_E9F1D0BD4600C3E3 FOREIGN KEY (node_id) REFERENCES agents (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE security_policy_revisions ADD CONSTRAINT FK_E9F1D0BDB03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL');
        }

        if (!$schema->hasTable('security_events')) {
            $this->addSql('CREATE TABLE security_events (id INT AUTO_INCREMENT NOT NULL, node_id VARCHAR(64) NOT NULL, direction VARCHAR(16) NOT NULL, source VARCHAR(32) NOT NULL, reason VARCHAR(120) DEFAULT NULL, ip VARCHAR(64) DEFAULT NULL, rule VARCHAR(120) DEFAULT NULL, count INT DEFAULT NULL, occurred_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_security_events_occurred (occurred_at), INDEX idx_security_events_direction (direction), INDEX idx_security_events_source (source), INDEX idx_security_events_ip (ip), INDEX idx_security_events_rule (rule), INDEX IDX_6AB7F8AF4600C3E3 (node_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE security_events ADD CONSTRAINT FK_6AB7F8AF4600C3E3 FOREIGN KEY (node_id) REFERENCES agents (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('security_events')) {
            $this->addSql('DROP TABLE security_events');
        }

        if ($schema->hasTable('security_policy_revisions')) {
            $this->addSql('DROP TABLE security_policy_revisions');
        }
    }
}
