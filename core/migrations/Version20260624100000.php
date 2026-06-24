<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260624100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Change instance_config on musicbot_instances from LONGTEXT to native JSON so Doctrine mapping and schema stay in sync.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE musicbot_instances CHANGE instance_config instance_config JSON DEFAULT '{}' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE musicbot_instances CHANGE instance_config instance_config LONGTEXT NOT NULL DEFAULT '{}' COMMENT '(DC2Type:json)'");
    }
}
