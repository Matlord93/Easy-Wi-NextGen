<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260504101500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add smtp_enabled and abuse_policy_enabled to mail_policies';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mail_policies ADD smtp_enabled TINYINT(1) DEFAULT 1 NOT NULL, ADD abuse_policy_enabled TINYINT(1) DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mail_policies DROP smtp_enabled, DROP abuse_policy_enabled');
    }
}

