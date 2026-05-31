<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20250305100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store SinusBot instance metadata such as bot id, web port, and last seen timestamp.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('sinusbot_instances')) {
            return;
        }

        $this->addSql('ALTER TABLE sinusbot_instances ADD bot_id VARCHAR(64) DEFAULT NULL, ADD web_port INT DEFAULT NULL, ADD last_seen_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        if (!$this->tableExists('sinusbot_instances')) {
            return;
        }

        $this->addSql('ALTER TABLE sinusbot_instances DROP bot_id, DROP web_port, DROP last_seen_at');
    }

    private function tableExists(string $table): bool
    {
        try {
            return $this->connection->createSchemaManager()->tablesExist([$table]);
        } catch (\Throwable) {
            return false;
        }
    }
}
