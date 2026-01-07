<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250219090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email verification and consent fields to users.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD email_verified_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD email_verification_token_hash VARCHAR(64) DEFAULT NULL, ADD email_verification_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD terms_accepted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD terms_accepted_ip VARCHAR(45) DEFAULT NULL, ADD privacy_accepted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD privacy_accepted_ip VARCHAR(45) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_users_email_verification_token ON users (email_verification_token_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_users_email_verification_token ON users');
        $this->addSql('ALTER TABLE users DROP email_verified_at, DROP email_verification_token_hash, DROP email_verification_expires_at, DROP terms_accepted_at, DROP terms_accepted_ip, DROP privacy_accepted_at, DROP privacy_accepted_ip');
    }
}
