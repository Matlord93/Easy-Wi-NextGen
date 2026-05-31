<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260612120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cascade deletes for TS6 virtual server relationships.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('ts6_virtual_servers')) {
            $this->dropForeignKey('ts6_virtual_servers', 'node_id', 'ts6_nodes');
            $this->addSql('ALTER TABLE ts6_virtual_servers ADD CONSTRAINT FK_TS6_VIRTUAL_SERVERS_NODE FOREIGN KEY (node_id) REFERENCES ts6_nodes (id) ON DELETE CASCADE');
        }

        if ($schema->hasTable('ts6_tokens')) {
            $this->dropForeignKey('ts6_tokens', 'virtual_server_id', 'ts6_virtual_servers');
            $this->addSql('ALTER TABLE ts6_tokens ADD CONSTRAINT FK_TS6_TOKENS_SERVER FOREIGN KEY (virtual_server_id) REFERENCES ts6_virtual_servers (id) ON DELETE CASCADE');
        }

        if ($schema->hasTable('ts6_viewers')) {
            $this->dropForeignKey('ts6_viewers', 'virtual_server_id', 'ts6_virtual_servers');
            $this->addSql('ALTER TABLE ts6_viewers ADD CONSTRAINT FK_TS6_VIEWERS_SERVER FOREIGN KEY (virtual_server_id) REFERENCES ts6_virtual_servers (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('ts6_viewers')) {
            $this->dropForeignKey('ts6_viewers', 'virtual_server_id', 'ts6_virtual_servers');
            $this->addSql('ALTER TABLE ts6_viewers ADD CONSTRAINT FK_TS6_VIEWERS_SERVER FOREIGN KEY (virtual_server_id) REFERENCES ts6_virtual_servers (id)');
        }

        if ($schema->hasTable('ts6_tokens')) {
            $this->dropForeignKey('ts6_tokens', 'virtual_server_id', 'ts6_virtual_servers');
            $this->addSql('ALTER TABLE ts6_tokens ADD CONSTRAINT FK_TS6_TOKENS_SERVER FOREIGN KEY (virtual_server_id) REFERENCES ts6_virtual_servers (id)');
        }

        if ($schema->hasTable('ts6_virtual_servers')) {
            $this->dropForeignKey('ts6_virtual_servers', 'node_id', 'ts6_nodes');
            $this->addSql('ALTER TABLE ts6_virtual_servers ADD CONSTRAINT FK_TS6_VIRTUAL_SERVERS_NODE FOREIGN KEY (node_id) REFERENCES ts6_nodes (id)');
        }
    }

    private function dropForeignKey(string $table, string $column, string $referencedTable): void
    {
        $constraint = $this->connection->fetchOne(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME = ?',
            [$table, $column, $referencedTable],
        );

        if (is_string($constraint) && $constraint !== '') {
            $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $table, $constraint));
        }
    }
}
