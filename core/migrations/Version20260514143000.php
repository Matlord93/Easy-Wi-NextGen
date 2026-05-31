<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260514143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ticket attachment metadata.';
    }

    public function up(Schema $schema): void
    {
        if ($this->tableExists('ticket_attachments')) {
            return;
        }

        if ($this->isSqlite()) {
            $this->addSql('CREATE TABLE ticket_attachments (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, ticket_id INTEGER NOT NULL, message_id INTEGER NOT NULL, uploaded_by_id INTEGER NOT NULL, original_name VARCHAR(255) NOT NULL, storage_path VARCHAR(255) NOT NULL, mime_type VARCHAR(120) NOT NULL, size_bytes INTEGER NOT NULL, created_at DATETIME NOT NULL)');
        } elseif ($this->isPostgreSql()) {
            $this->addSql('CREATE TABLE ticket_attachments (id SERIAL NOT NULL, ticket_id INT NOT NULL, message_id INT NOT NULL, uploaded_by_id INT NOT NULL, original_name VARCHAR(255) NOT NULL, storage_path VARCHAR(255) NOT NULL, mime_type VARCHAR(120) NOT NULL, size_bytes INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        } else {
            $this->addSql('CREATE TABLE ticket_attachments (id INT AUTO_INCREMENT NOT NULL, ticket_id INT NOT NULL, message_id INT NOT NULL, uploaded_by_id INT NOT NULL, original_name VARCHAR(255) NOT NULL, storage_path VARCHAR(255) NOT NULL, mime_type VARCHAR(120) NOT NULL, size_bytes INT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        $this->addSql('CREATE INDEX idx_ticket_attachments_ticket_id ON ticket_attachments (ticket_id)');
        $this->addSql('CREATE INDEX idx_ticket_attachments_message_id ON ticket_attachments (message_id)');
        $this->addSql('CREATE INDEX idx_ticket_attachments_uploaded_by_id ON ticket_attachments (uploaded_by_id)');

        if (!$this->isSqlite()) {
            $this->addSql('ALTER TABLE ticket_attachments ADD CONSTRAINT FK_TICKET_ATTACHMENTS_TICKET FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE ticket_attachments ADD CONSTRAINT FK_TICKET_ATTACHMENTS_MESSAGE FOREIGN KEY (message_id) REFERENCES ticket_messages (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE ticket_attachments ADD CONSTRAINT FK_TICKET_ATTACHMENTS_UPLOADED_BY FOREIGN KEY (uploaded_by_id) REFERENCES users (id)');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$this->tableExists('ticket_attachments')) {
            return;
        }

        if (!$this->isSqlite()) {
            foreach (['FK_TICKET_ATTACHMENTS_TICKET', 'FK_TICKET_ATTACHMENTS_MESSAGE', 'FK_TICKET_ATTACHMENTS_UPLOADED_BY'] as $foreignKey) {
                if ($this->foreignKeyExists('ticket_attachments', $foreignKey)) {
                    $dropKeyword = $this->isPostgreSql() ? 'DROP CONSTRAINT' : 'DROP FOREIGN KEY';
                    $this->addSql(sprintf('ALTER TABLE ticket_attachments %s %s', $dropKeyword, $foreignKey));
                }
            }
        }

        $this->addSql('DROP TABLE ticket_attachments');
    }

    private function tableExists(string $table): bool
    {
        try {
            return $this->connection->createSchemaManager()->tablesExist([$table]);
        } catch (\Throwable) {
            return false;
        }
    }

    private function foreignKeyExists(string $table, string $foreignKey): bool
    {
        try {
            $foreignKeys = $this->connection->createSchemaManager()->listTableForeignKeys($table);
        } catch (\Throwable) {
            return false;
        }

        foreach ($foreignKeys as $key) {
            if (strcasecmp($key->getName(), $foreignKey) === 0) {
                return true;
            }
        }

        return false;
    }

    private function isSqlite(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof SQLitePlatform;
    }

    private function isPostgreSql(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }
}
