<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250218120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add customer profiles and invoice preferences.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE customer_profiles (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, address VARCHAR(255) NOT NULL, postal VARCHAR(40) NOT NULL, city VARCHAR(120) NOT NULL, country VARCHAR(2) NOT NULL, phone VARCHAR(40) DEFAULT NULL, company VARCHAR(160) DEFAULT NULL, vat_id VARCHAR(40) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_customer_profiles_customer (customer_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE invoice_preferences (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, locale VARCHAR(20) NOT NULL, email_delivery TINYINT(1) NOT NULL, pdf_download_history TINYINT(1) NOT NULL, default_payment_method VARCHAR(60) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_invoice_preferences_customer (customer_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE customer_profiles ADD CONSTRAINT FK_8E72A27C9395C3F3 FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE invoice_preferences ADD CONSTRAINT FK_2B7D5B009395C3F3 FOREIGN KEY (customer_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_profiles DROP FOREIGN KEY FK_8E72A27C9395C3F3');
        $this->addSql('ALTER TABLE invoice_preferences DROP FOREIGN KEY FK_2B7D5B009395C3F3');
        $this->addSql('DROP TABLE invoice_preferences');
        $this->addSql('DROP TABLE customer_profiles');
    }
}
