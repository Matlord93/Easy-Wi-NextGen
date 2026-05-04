<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
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
        if (!$this->isMySql()) {
            $this->write('Skipping migration on non-MySQL platform.');

            return;
        }

        if (!$this->hasTable('mail_policies')) {
            $this->write('Skipping mail_policies column additions because table mail_policies does not exist in this database.');

            return;
        }

        if (!$this->hasColumn('mail_policies', 'smtp_enabled')) {
            $this->addSql('ALTER TABLE mail_policies ADD smtp_enabled TINYINT(1) DEFAULT 1 NOT NULL');
        }

        if (!$this->hasColumn('mail_policies', 'abuse_policy_enabled')) {
            $this->addSql('ALTER TABLE mail_policies ADD abuse_policy_enabled TINYINT(1) DEFAULT 1 NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$this->isMySql()) {
            $this->write('Skipping rollback on non-MySQL platform.');

            return;
        }

        if (!$this->hasTable('mail_policies')) {
            return;
        }

        if ($this->hasColumn('mail_policies', 'smtp_enabled')) {
            $this->addSql('ALTER TABLE mail_policies DROP smtp_enabled');
        }

        if ($this->hasColumn('mail_policies', 'abuse_policy_enabled')) {
            $this->addSql('ALTER TABLE mail_policies DROP abuse_policy_enabled');
        }
    }


    private function isMySql(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof MySQLPlatform;
    }

    private function hasTable(string $table): bool
    {
        $database = (string) $this->connection->fetchOne('SELECT DATABASE()');

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, $table],
        ) > 0;
    }

    private function hasColumn(string $table, string $column): bool
    {
        $database = (string) $this->connection->fetchOne('SELECT DATABASE()');

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$database, $table, $column],
        ) > 0;
    }
}
