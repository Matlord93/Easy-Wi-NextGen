<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250311120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add audit logs table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE audit_logs (id INT AUTO_INCREMENT NOT NULL, actor_id INT DEFAULT NULL, action VARCHAR(120) NOT NULL, payload JSON NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', hash_prev VARCHAR(64) DEFAULT NULL, hash_current VARCHAR(64) NOT NULL, INDEX IDX_AUDIT_LOGS_ACTOR (actor_id), INDEX idx_audit_logs_created_at (created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE audit_logs ADD CONSTRAINT FK_AUDIT_LOGS_ACTOR FOREIGN KEY (actor_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_logs DROP FOREIGN KEY FK_AUDIT_LOGS_ACTOR');
        $this->addSql('DROP TABLE audit_logs');
    }
}
