<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20250315110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add backup targets and link backup definitions to optional targets.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('backup_targets')) {
            $this->addSql('CREATE TABLE backup_targets (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, type VARCHAR(20) NOT NULL, label VARCHAR(160) NOT NULL, config JSON NOT NULL, encrypted_credentials JSON DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_71B9A0D79395C3F3 (customer_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE backup_targets ADD CONSTRAINT FK_71B9A0D79395C3F3 FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE');
        }

        if ($schema->hasTable('backup_definitions')) {
            $table = $schema->getTable('backup_definitions');
            if (!$table->hasColumn('backup_target_id')) {
                $this->addSql('ALTER TABLE backup_definitions ADD backup_target_id INT DEFAULT NULL');
                $this->addSql('ALTER TABLE backup_definitions ADD CONSTRAINT FK_91BA3AA0B514F4F0 FOREIGN KEY (backup_target_id) REFERENCES backup_targets (id) ON DELETE SET NULL');
                $this->addSql('CREATE INDEX IDX_91BA3AA0B514F4F0 ON backup_definitions (backup_target_id)');
            }
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('backup_definitions')) {
            $table = $schema->getTable('backup_definitions');
            if ($table->hasColumn('backup_target_id')) {
                $this->addSql('ALTER TABLE backup_definitions DROP FOREIGN KEY FK_91BA3AA0B514F4F0');
                $this->addSql('DROP INDEX IDX_91BA3AA0B514F4F0 ON backup_definitions');
                $this->addSql('ALTER TABLE backup_definitions DROP backup_target_id');
            }
        }

        if ($schema->hasTable('backup_targets')) {
            $this->addSql('DROP TABLE backup_targets');
        }
    }
}
