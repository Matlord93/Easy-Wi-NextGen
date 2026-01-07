<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250301090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add instance update policy and build metadata tracking.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instances ADD update_policy VARCHAR(16) NOT NULL DEFAULT \'manual\', ADD locked_build_id VARCHAR(64) DEFAULT NULL, ADD locked_version VARCHAR(120) DEFAULT NULL, ADD current_build_id VARCHAR(64) DEFAULT NULL, ADD current_version VARCHAR(120) DEFAULT NULL, ADD previous_build_id VARCHAR(64) DEFAULT NULL, ADD previous_version VARCHAR(120) DEFAULT NULL, ADD last_update_queued_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instances DROP update_policy, DROP locked_build_id, DROP locked_version, DROP current_build_id, DROP current_version, DROP previous_build_id, DROP previous_version, DROP last_update_queued_at');
    }
}
