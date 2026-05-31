<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20250315121500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add two-factor authentication fields to users.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }

        $table = $schema->getTable('users');
        if (!$table->hasColumn('totp_secret_encrypted')) {
            $this->addSql('ALTER TABLE users ADD totp_secret_encrypted LONGTEXT DEFAULT NULL');
        }
        if (!$table->hasColumn('totp_enabled')) {
            $this->addSql('ALTER TABLE users ADD totp_enabled TINYINT(1) NOT NULL DEFAULT 0');
        }
        if (!$table->hasColumn('totp_recovery_codes')) {
            $this->addSql('ALTER TABLE users ADD totp_recovery_codes JSON DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }

        $table = $schema->getTable('users');
        if ($table->hasColumn('totp_recovery_codes')) {
            $this->addSql('ALTER TABLE users DROP totp_recovery_codes');
        }
        if ($table->hasColumn('totp_enabled')) {
            $this->addSql('ALTER TABLE users DROP totp_enabled');
        }
        if ($table->hasColumn('totp_secret_encrypted')) {
            $this->addSql('ALTER TABLE users DROP totp_secret_encrypted');
        }
    }
}
