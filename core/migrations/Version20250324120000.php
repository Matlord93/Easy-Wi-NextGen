<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250324120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add app settings table for installer and admin configuration.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('app_settings')) {
            return;
        }

        $this->addSql('CREATE TABLE app_settings (setting_key VARCHAR(80) NOT NULL, value JSON DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(setting_key)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('app_settings')) {
            $this->addSql('DROP TABLE app_settings');
        }
    }
}
