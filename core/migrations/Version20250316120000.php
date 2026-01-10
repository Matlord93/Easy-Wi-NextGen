<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250316120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add software disk limits for instances and node disk protection settings.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('instances')) {
            $this->addSql("ALTER TABLE instances ADD disk_limit_bytes BIGINT NOT NULL, ADD disk_used_bytes BIGINT NOT NULL DEFAULT 0, ADD disk_state VARCHAR(20) NOT NULL DEFAULT 'ok', ADD disk_last_scanned_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ADD disk_scan_error LONGTEXT DEFAULT NULL");
            $this->addSql('UPDATE instances SET disk_limit_bytes = disk_limit * 1024 * 1024');
        }

        if ($schema->hasTable('agents')) {
            $this->addSql('ALTER TABLE agents ADD disk_scan_interval_seconds INT NOT NULL DEFAULT 180, ADD disk_warning_percent INT NOT NULL DEFAULT 85, ADD disk_hard_block_percent INT NOT NULL DEFAULT 120, ADD node_disk_protection_threshold_percent INT NOT NULL DEFAULT 5, ADD node_disk_protection_override_until DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('instances')) {
            $this->addSql('ALTER TABLE instances DROP disk_limit_bytes, DROP disk_used_bytes, DROP disk_state, DROP disk_last_scanned_at, DROP disk_scan_error');
        }

        if ($schema->hasTable('agents')) {
            $this->addSql('ALTER TABLE agents DROP disk_scan_interval_seconds, DROP disk_warning_percent, DROP disk_hard_block_percent, DROP node_disk_protection_threshold_percent, DROP node_disk_protection_override_until');
        }
    }
}
