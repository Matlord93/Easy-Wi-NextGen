<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250323120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add webspace status, docroot, limits, and system username.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('webspaces')) {
            return;
        }

        $this->addSql("ALTER TABLE webspaces ADD status VARCHAR(20) DEFAULT 'active' NOT NULL");
        $this->addSql("ALTER TABLE webspaces ADD docroot VARCHAR(255) DEFAULT '' NOT NULL");
        $this->addSql('ALTER TABLE webspaces ADD disk_limit_bytes INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE webspaces ADD ftp_enabled TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE webspaces ADD sftp_enabled TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql("ALTER TABLE webspaces ADD system_username VARCHAR(64) DEFAULT '' NOT NULL");
        $this->addSql('ALTER TABLE webspaces ADD deleted_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)"');

        $this->addSql('UPDATE webspaces SET docroot = CONCAT(path, \'/public\') WHERE docroot = \'\' OR docroot IS NULL');
        $this->addSql('UPDATE webspaces SET disk_limit_bytes = 0 WHERE disk_limit_bytes IS NULL');
        $this->addSql('UPDATE webspaces SET ftp_enabled = 0 WHERE ftp_enabled IS NULL');
        $this->addSql('UPDATE webspaces SET sftp_enabled = 0 WHERE sftp_enabled IS NULL');
        $this->addSql('UPDATE webspaces SET system_username = CONCAT(\'ws\', id) WHERE system_username = \'\' OR system_username IS NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('webspaces')) {
            return;
        }

        $this->addSql('ALTER TABLE webspaces DROP status');
        $this->addSql('ALTER TABLE webspaces DROP docroot');
        $this->addSql('ALTER TABLE webspaces DROP disk_limit_bytes');
        $this->addSql('ALTER TABLE webspaces DROP ftp_enabled');
        $this->addSql('ALTER TABLE webspaces DROP sftp_enabled');
        $this->addSql('ALTER TABLE webspaces DROP system_username');
        $this->addSql('ALTER TABLE webspaces DROP deleted_at');
    }
}
