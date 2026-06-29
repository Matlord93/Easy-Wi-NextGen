<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260627200000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add musicbot_roles and musicbot_role_assignments tables for per-bot role & permission system.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE musicbot_roles (
            id          INT AUTO_INCREMENT NOT NULL,
            instance_id INT NOT NULL,
            name        VARCHAR(80) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            permissions JSON NOT NULL DEFAULT (\'[]\'),
            channels    JSON NOT NULL DEFAULT (\'[]\'),
            is_default  TINYINT(1) NOT NULL DEFAULT 0,
            position    INT NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL,
            updated_at  DATETIME NOT NULL,
            INDEX idx_musicbot_roles_instance (instance_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE musicbot_roles
            ADD CONSTRAINT fk_musicbot_roles_instance
            FOREIGN KEY (instance_id) REFERENCES musicbot_instances (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE musicbot_role_assignments (
            id           INT AUTO_INCREMENT NOT NULL,
            role_id      INT NOT NULL,
            granted_by   INT DEFAULT NULL,
            subject_type VARCHAR(32) NOT NULL,
            subject_id   VARCHAR(128) NOT NULL,
            created_at   DATETIME NOT NULL,
            INDEX idx_mra_role (role_id),
            INDEX idx_mra_subject (subject_type, subject_id),
            UNIQUE INDEX uniq_mra_role_subject (role_id, subject_type, subject_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE musicbot_role_assignments
            ADD CONSTRAINT fk_mra_role
            FOREIGN KEY (role_id) REFERENCES musicbot_roles (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE musicbot_role_assignments
            ADD CONSTRAINT fk_mra_granted_by
            FOREIGN KEY (granted_by) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE musicbot_role_assignments DROP FOREIGN KEY fk_mra_role');
        $this->addSql('ALTER TABLE musicbot_role_assignments DROP FOREIGN KEY fk_mra_granted_by');
        $this->addSql('DROP TABLE IF EXISTS musicbot_role_assignments');

        $this->addSql('ALTER TABLE musicbot_roles DROP FOREIGN KEY fk_musicbot_roles_instance');
        $this->addSql('DROP TABLE IF EXISTS musicbot_roles');
    }
}
