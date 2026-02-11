<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add scalar instance_metric_samples table for per-instance CPU/RAM/task metrics.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('instance_metric_samples')) {
            return;
        }

        $this->addSql('CREATE TABLE instance_metric_samples (id INT AUTO_INCREMENT NOT NULL, instance_id INT NOT NULL, cpu_percent DOUBLE PRECISION DEFAULT NULL, mem_used_bytes BIGINT DEFAULT NULL, tasks_current INT DEFAULT NULL, collected_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', error_code VARCHAR(120) DEFAULT NULL, INDEX idx_instance_metric_samples_instance_collected (instance_id, collected_at), INDEX IDX_D9719841B6BD1646 (instance_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE instance_metric_samples ADD CONSTRAINT FK_D9719841B6BD1646 FOREIGN KEY (instance_id) REFERENCES `instance` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('instance_metric_samples')) {
            return;
        }

        $this->addSql('DROP TABLE instance_metric_samples');
    }
}
