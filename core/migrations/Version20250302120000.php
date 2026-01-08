<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250302120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add notifications table for panel alerts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notifications (id INT AUTO_INCREMENT NOT NULL, recipient_id INT NOT NULL, category VARCHAR(32) NOT NULL, title VARCHAR(160) NOT NULL, body VARCHAR(255) NOT NULL, action_url VARCHAR(255) DEFAULT NULL, event_key VARCHAR(120) NOT NULL, read_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX notifications_recipient_created_idx (recipient_id, created_at), UNIQUE INDEX notifications_recipient_event_key_idx (recipient_id, event_key), INDEX IDX_6000B0D3E92F8F78 (recipient_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE notifications ADD CONSTRAINT FK_6000B0D3E92F8F78 FOREIGN KEY (recipient_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notifications DROP FOREIGN KEY FK_6000B0D3E92F8F78');
        $this->addSql('DROP TABLE notifications');
    }
}
