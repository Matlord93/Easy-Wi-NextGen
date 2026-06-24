<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260624000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add instance_config JSON column to musicbot_instances for persistent bot settings (autostart, command_prefix, default_volume).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE musicbot_instances ADD instance_config LONGTEXT NOT NULL DEFAULT '{}' COMMENT '(DC2Type:json)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE musicbot_instances DROP instance_config');
    }
}
