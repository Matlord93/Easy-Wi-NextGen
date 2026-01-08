<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250220120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add GDPR export, deletion request, and retention policy tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE gdpr_exports (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, status VARCHAR(255) NOT NULL, file_name VARCHAR(160) NOT NULL, file_size INT NOT NULL, encrypted_payload JSON NOT NULL, requested_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", ready_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", expires_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", INDEX idx_gdpr_exports_customer (customer_id), INDEX idx_gdpr_exports_status (status), INDEX idx_gdpr_exports_expires (expires_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE gdpr_exports ADD CONSTRAINT FK_5AFA2E359395C3F3 FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('CREATE TABLE gdpr_deletion_requests (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, status VARCHAR(255) NOT NULL, job_id VARCHAR(32) DEFAULT NULL, requested_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", processed_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", INDEX idx_gdpr_deletion_customer (customer_id), INDEX idx_gdpr_deletion_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE gdpr_deletion_requests ADD CONSTRAINT FK_8E540AD99395C3F3 FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('CREATE TABLE retention_policies (id INT AUTO_INCREMENT NOT NULL, ticket_retention_days INT NOT NULL, log_retention_days INT NOT NULL, session_retention_days INT NOT NULL, created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", updated_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gdpr_exports DROP FOREIGN KEY FK_5AFA2E359395C3F3');
        $this->addSql('ALTER TABLE gdpr_deletion_requests DROP FOREIGN KEY FK_8E540AD99395C3F3');
        $this->addSql('DROP TABLE gdpr_exports');
        $this->addSql('DROP TABLE gdpr_deletion_requests');
        $this->addSql('DROP TABLE retention_policies');
    }
}
