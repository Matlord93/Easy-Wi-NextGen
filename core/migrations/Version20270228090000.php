<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20270228090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create missing Hosting Panel tables for jobs, audits, metrics, secrets, modules, roles, and users.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('hp_role')) {
            $this->addSql("CREATE TABLE hp_role (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(60) NOT NULL, UNIQUE INDEX UNIQ_HP_ROLE_NAME (name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        }

        if (!$schema->hasTable('hp_user')) {
            $this->addSql("CREATE TABLE hp_user (id INT AUTO_INCREMENT NOT NULL, role_id INT NOT NULL, email VARCHAR(180) NOT NULL, INDEX IDX_HP_USER_ROLE_ID (role_id), UNIQUE INDEX UNIQ_HP_USER_EMAIL (email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
            $this->addSql('ALTER TABLE hp_user ADD CONSTRAINT FK_HP_USER_ROLE FOREIGN KEY (role_id) REFERENCES hp_role (id)');
        }

        if (!$schema->hasTable('hp_secret')) {
            $this->addSql("CREATE TABLE hp_secret (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, ciphertext VARCHAR(255) NOT NULL, nonce VARCHAR(255) NOT NULL, key_version VARCHAR(20) NOT NULL, rotated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_HP_SECRET_NAME (name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        }

        if (!$schema->hasTable('hp_module')) {
            $this->addSql("CREATE TABLE hp_module (id INT AUTO_INCREMENT NOT NULL, node_id INT NOT NULL, type VARCHAR(40) NOT NULL, name VARCHAR(60) NOT NULL, desired_state JSON NOT NULL, actual_state JSON NOT NULL, INDEX IDX_HP_MODULE_NODE_ID (node_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
            $this->addSql('ALTER TABLE hp_module ADD CONSTRAINT FK_HP_MODULE_NODE FOREIGN KEY (node_id) REFERENCES hp_node (id) ON DELETE CASCADE');
        }

        if (!$schema->hasTable('hp_job')) {
            $this->addSql("CREATE TABLE hp_job (id INT AUTO_INCREMENT NOT NULL, node_id INT NOT NULL, type VARCHAR(50) NOT NULL, status VARCHAR(255) NOT NULL, idempotency_key VARCHAR(128) NOT NULL, payload JSON NOT NULL, INDEX IDX_HP_JOB_NODE_ID (node_id), UNIQUE INDEX uniq_hp_job_idempotency (idempotency_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
            $this->addSql('ALTER TABLE hp_job ADD CONSTRAINT FK_HP_JOB_NODE FOREIGN KEY (node_id) REFERENCES hp_node (id) ON DELETE CASCADE');
        }

        if (!$schema->hasTable('hp_job_run')) {
            $this->addSql("CREATE TABLE hp_job_run (id INT AUTO_INCREMENT NOT NULL, job_id INT NOT NULL, status VARCHAR(255) NOT NULL, started_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', finished_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', result JSON NOT NULL, INDEX IDX_HP_JOB_RUN_JOB_ID (job_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
            $this->addSql('ALTER TABLE hp_job_run ADD CONSTRAINT FK_HP_JOB_RUN_JOB FOREIGN KEY (job_id) REFERENCES hp_job (id) ON DELETE CASCADE');
        }

        if (!$schema->hasTable('hp_metric_sample')) {
            $this->addSql("CREATE TABLE hp_metric_sample (id INT AUTO_INCREMENT NOT NULL, node_id INT NOT NULL, metric VARCHAR(40) NOT NULL, value DOUBLE PRECISION NOT NULL, sampled_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_HP_METRIC_SAMPLE_NODE_ID (node_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
            $this->addSql('ALTER TABLE hp_metric_sample ADD CONSTRAINT FK_HP_METRIC_SAMPLE_NODE FOREIGN KEY (node_id) REFERENCES hp_node (id) ON DELETE CASCADE');
        }

        if (!$schema->hasTable('hp_audit_log')) {
            $this->addSql("CREATE TABLE hp_audit_log (id INT AUTO_INCREMENT NOT NULL, actor VARCHAR(120) NOT NULL, action VARCHAR(120) NOT NULL, target_type VARCHAR(120) NOT NULL, target_id VARCHAR(120) NOT NULL, before_state JSON NOT NULL, after_state JSON NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_HP_AUDIT_TARGET (target_type, target_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('hp_job_run')) {
            $this->addSql('DROP TABLE hp_job_run');
        }

        if ($schema->hasTable('hp_job')) {
            $this->addSql('DROP TABLE hp_job');
        }

        if ($schema->hasTable('hp_metric_sample')) {
            $this->addSql('DROP TABLE hp_metric_sample');
        }

        if ($schema->hasTable('hp_module')) {
            $this->addSql('DROP TABLE hp_module');
        }

        if ($schema->hasTable('hp_audit_log')) {
            $this->addSql('DROP TABLE hp_audit_log');
        }

        if ($schema->hasTable('hp_secret')) {
            $this->addSql('DROP TABLE hp_secret');
        }

        if ($schema->hasTable('hp_user')) {
            $this->addSql('DROP TABLE hp_user');
        }

        if ($schema->hasTable('hp_role')) {
            $this->addSql('DROP TABLE hp_role');
        }
    }
}
