<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122090000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Enable the local CAPTCHA for existing contact forms.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE app_settings
            SET value = 'true', updated_at = CURRENT_TIMESTAMP
            WHERE setting_key = 'anti_abuse_enable_captcha_contact' AND JSON_UNQUOTE(value) = 'false'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // Keep the current setting, which administrators can change after the update.
    }
}
