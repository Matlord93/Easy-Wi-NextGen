<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260526133000 extends AbstractMigration
{
    public function getDescription(): string { return 'Create teamspeak update logs table.'; }
    public function up(Schema $schema): void
    {
        if ($this->tableExists('teamspeak_update_logs')) { return; }
        $this->addSql('CREATE TABLE teamspeak_update_logs (id INT AUTO_INCREMENT NOT NULL, executed_by_id INT NOT NULL, instance_type VARCHAR(8) NOT NULL, instance_id INT NOT NULL, old_version VARCHAR(32) DEFAULT NULL, target_version VARCHAR(32) DEFAULT NULL, status VARCHAR(16) NOT NULL, started_at DATETIME NOT NULL, ended_at DATETIME DEFAULT NULL, backup_path VARCHAR(255) DEFAULT NULL, download_url VARCHAR(500) DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, error_details LONGTEXT DEFAULT NULL, steps JSON DEFAULT NULL, INDEX IDX_25BF65303F5A2C5A (executed_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE teamspeak_update_logs ADD CONSTRAINT FK_25BF65303F5A2C5A FOREIGN KEY (executed_by_id) REFERENCES users (id)');
    }
    public function down(Schema $schema): void { if ($this->tableExists('teamspeak_update_logs')) { $this->addSql('DROP TABLE teamspeak_update_logs'); } }
    private function tableExists(string $table): bool { try { return $this->connection->createSchemaManager()->tablesExist([$table]); } catch (\Throwable) { return false; } }
}
