<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250311150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ticket templates, quick replies, and admin signatures.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ticket_templates (id INT AUTO_INCREMENT NOT NULL, admin_id INT NOT NULL, title VARCHAR(120) NOT NULL, subject VARCHAR(160) NOT NULL, category VARCHAR(20) NOT NULL, priority VARCHAR(20) NOT NULL, body LONGTEXT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_ticket_templates_admin (admin_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE ticket_quick_replies (id INT AUTO_INCREMENT NOT NULL, admin_id INT NOT NULL, title VARCHAR(120) NOT NULL, body LONGTEXT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_ticket_quick_replies_admin (admin_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE users ADD admin_signature LONGTEXT DEFAULT NULL');

        $this->addSql('ALTER TABLE ticket_templates ADD CONSTRAINT FK_TICKET_TEMPLATES_ADMIN FOREIGN KEY (admin_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ticket_quick_replies ADD CONSTRAINT FK_TICKET_QUICK_REPLIES_ADMIN FOREIGN KEY (admin_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket_templates DROP FOREIGN KEY FK_TICKET_TEMPLATES_ADMIN');
        $this->addSql('ALTER TABLE ticket_quick_replies DROP FOREIGN KEY FK_TICKET_QUICK_REPLIES_ADMIN');

        $this->addSql('DROP TABLE ticket_templates');
        $this->addSql('DROP TABLE ticket_quick_replies');
        $this->addSql('ALTER TABLE users DROP admin_signature');
    }
}
