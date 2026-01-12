<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250305093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add immutable invoice archive storage.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE invoice_archives (id INT AUTO_INCREMENT NOT NULL, invoice_id INT NOT NULL, file_name VARCHAR(160) NOT NULL, content_type VARCHAR(80) NOT NULL, file_size INT NOT NULL, pdf_hash VARCHAR(64) NOT NULL, pdf_data LONGBLOB NOT NULL, archived_year INT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_invoice_archives_invoice (invoice_id), INDEX idx_invoice_archives_year (archived_year), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE invoice_archives ADD CONSTRAINT FK_INVOICE_ARCHIVES_INVOICE FOREIGN KEY (invoice_id) REFERENCES invoices (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_archives DROP FOREIGN KEY FK_INVOICE_ARCHIVES_INVOICE');
        $this->addSql('DROP TABLE invoice_archives');
    }
}
