<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20260920020000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add per-category notification channel preferences'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification_preferences (id INT AUTO_INCREMENT NOT NULL, recipient_id INT NOT NULL, category VARCHAR(32) NOT NULL, in_app_enabled TINYINT(1) NOT NULL, email_enabled TINYINT(1) NOT NULL, webhook_enabled TINYINT(1) NOT NULL, INDEX IDX_NOTIFICATION_PREF_RECIPIENT (recipient_id), UNIQUE INDEX uniq_notification_preference_user_category (recipient_id, category), PRIMARY KEY(id), CONSTRAINT FK_NOTIFICATION_PREF_RECIPIENT FOREIGN KEY (recipient_id) REFERENCES users (id) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE notification_preferences'); }
}
