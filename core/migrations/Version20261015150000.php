<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'GDPR export hardening: add download token fields for one-time TTL links.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('gdpr_exports')) {
            return;
        }

        $table = $schema->getTable('gdpr_exports');
        if (!$table->hasColumn('download_token_hash')) {
            $this->addSql('ALTER TABLE gdpr_exports ADD download_token_hash VARCHAR(255) DEFAULT NULL');
        }
        if (!$table->hasColumn('download_token_expires_at')) {
            $this->addSql("ALTER TABLE gdpr_exports ADD download_token_expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        }
        if (!$table->hasIndex('idx_gdpr_exports_token_expires')) {
            $this->addSql('CREATE INDEX idx_gdpr_exports_token_expires ON gdpr_exports (download_token_expires_at)');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('gdpr_exports')) {
            return;
        }

        $table = $schema->getTable('gdpr_exports');
        if ($table->hasIndex('idx_gdpr_exports_token_expires')) {
            $this->addSql('DROP INDEX idx_gdpr_exports_token_expires ON gdpr_exports');
        }
        if ($table->hasColumn('download_token_expires_at')) {
            $this->addSql('ALTER TABLE gdpr_exports DROP download_token_expires_at');
        }
        if ($table->hasColumn('download_token_hash')) {
            $this->addSql('ALTER TABLE gdpr_exports DROP download_token_hash');
        }
    }
}
