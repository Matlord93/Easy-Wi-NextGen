<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260201090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add webspace management settings (PHP settings, cron tasks, git repository).';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('webspaces')) {
            return;
        }

        $this->addSql('ALTER TABLE webspaces ADD php_settings JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE webspaces ADD cron_tasks LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE webspaces ADD git_repo_url VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE webspaces ADD git_branch VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('webspaces')) {
            return;
        }

        $this->addSql('ALTER TABLE webspaces DROP php_settings');
        $this->addSql('ALTER TABLE webspaces DROP cron_tasks');
        $this->addSql('ALTER TABLE webspaces DROP git_repo_url');
        $this->addSql('ALTER TABLE webspaces DROP git_branch');
    }
}
