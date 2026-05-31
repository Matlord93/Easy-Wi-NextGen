<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260601000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        // Default is 'multi' because pre-existing nodes were already wired for
        // multi-instance customer provisioning via /internal/sinusbot/instances.
        // Solo is a new explicit opt-in mode for single-customer setups.
        return 'Add instance_mode to sinusbot_nodes; fix sinusbot_instances.node_id FK to CASCADE on delete';
    }

    public function up(Schema $schema): void
    {
        if ($this->tableExists('sinusbot_nodes')) {
            if (!$this->columnExists('sinusbot_nodes', 'instance_mode')) {
                $this->addSql("ALTER TABLE sinusbot_nodes ADD instance_mode VARCHAR(16) NOT NULL DEFAULT 'multi' COMMENT ''");
            }
            $this->addSql('ALTER TABLE sinusbot_nodes CHANGE instance_mode instance_mode VARCHAR(16) NOT NULL');
        }

        if ($this->tableExists('sinusbot_instances') && !$this->fkHasCascade('sinusbot_instances', 'FK_4213A83E460D9FD7')) {
            if ($this->fkExists('sinusbot_instances', 'FK_4213A83E460D9FD7')) {
                $this->addSql('ALTER TABLE sinusbot_instances DROP FOREIGN KEY FK_4213A83E460D9FD7');
            }
            $this->addSql('ALTER TABLE sinusbot_instances ADD CONSTRAINT FK_4213A83E460D9FD7 FOREIGN KEY (node_id) REFERENCES sinusbot_nodes (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->tableExists('sinusbot_nodes') && $this->columnExists('sinusbot_nodes', 'instance_mode')) {
            $this->addSql('ALTER TABLE sinusbot_nodes DROP COLUMN instance_mode');
        }

        if ($this->tableExists('sinusbot_instances') && $this->fkExists('sinusbot_instances', 'FK_4213A83E460D9FD7')) {
            $this->addSql('ALTER TABLE sinusbot_instances DROP FOREIGN KEY FK_4213A83E460D9FD7');
            $this->addSql('ALTER TABLE sinusbot_instances ADD CONSTRAINT FK_4213A83E460D9FD7 FOREIGN KEY (node_id) REFERENCES sinusbot_nodes (id)');
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
                ['table' => $table]
            ) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column',
                ['table' => $table, 'column' => $column]
            ) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function fkExists(string $table, string $constraintName): bool
    {
        try {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND CONSTRAINT_NAME = :name AND CONSTRAINT_TYPE = \'FOREIGN KEY\'',
                ['table' => $table, 'name' => $constraintName]
            ) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function fkHasCascade(string $table, string $constraintName): bool
    {
        try {
            $deleteRule = $this->connection->fetchOne(
                'SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = :table AND CONSTRAINT_NAME = :name',
                ['table' => $table, 'name' => $constraintName]
            );

            return $deleteRule === 'CASCADE';
        } catch (\Throwable) {
            return false;
        }
    }
}
