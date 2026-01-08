<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250215090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create users table required for foreign keys.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('users')) {
            return;
        }

        $this->addSql('CREATE TABLE users (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, type VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', email_verified_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', email_verification_token_hash VARCHAR(64) DEFAULT NULL, email_verification_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', terms_accepted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', terms_accepted_ip VARCHAR(45) DEFAULT NULL, privacy_accepted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', privacy_accepted_ip VARCHAR(45) DEFAULT NULL, reseller_id INT DEFAULT NULL, UNIQUE INDEX uniq_users_email (email), INDEX idx_users_email_verification_token (email_verification_token_hash), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }

        $this->addSql('DROP TABLE users');
    }
}
