<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250218110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add consent logs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE consent_logs (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, type VARCHAR(255) NOT NULL, accepted_at DATETIME NOT NULL COMMENT \"(DC2Type:datetime_immutable)\", ip VARCHAR(64) NOT NULL, user_agent VARCHAR(255) NOT NULL, version VARCHAR(120) NOT NULL, INDEX IDX_4BB08C0A76ED395 (user_id), INDEX idx_consent_logs_type (type), INDEX idx_consent_logs_accepted (accepted_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE consent_logs ADD CONSTRAINT FK_4BB08C0A76ED395 FOREIGN KEY (user_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE consent_logs DROP FOREIGN KEY FK_4BB08C0A76ED395');
        $this->addSql('DROP TABLE consent_logs');
    }
}
