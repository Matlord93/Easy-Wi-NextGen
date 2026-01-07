<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250220100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add credit notes linked to invoices.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE credit_notes (id INT AUTO_INCREMENT NOT NULL, invoice_id INT NOT NULL, number VARCHAR(40) NOT NULL, status VARCHAR(20) NOT NULL, currency VARCHAR(3) NOT NULL, amount_cents INT NOT NULL, reason VARCHAR(255) DEFAULT NULL, issued_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_credit_notes_invoice_id (invoice_id), INDEX idx_credit_notes_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE credit_notes ADD CONSTRAINT FK_CREDIT_NOTES_INVOICE FOREIGN KEY (invoice_id) REFERENCES invoices (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE credit_notes DROP FOREIGN KEY FK_CREDIT_NOTES_INVOICE');
        $this->addSql('DROP TABLE credit_notes');
    }
}
