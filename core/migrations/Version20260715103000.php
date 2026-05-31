<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260715103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create metric_aggregates table for 1m/5m/1h rollups.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('metric_aggregates')) {
            return;
        }

        $this->addSql('CREATE TABLE metric_aggregates (id INT AUTO_INCREMENT NOT NULL, agent_id VARCHAR(64) NOT NULL, bucket VARCHAR(8) NOT NULL, bucket_start DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', sample_count INT NOT NULL, cpu_min DOUBLE PRECISION DEFAULT NULL, cpu_avg DOUBLE PRECISION DEFAULT NULL, cpu_max DOUBLE PRECISION DEFAULT NULL, memory_min DOUBLE PRECISION DEFAULT NULL, memory_avg DOUBLE PRECISION DEFAULT NULL, memory_max DOUBLE PRECISION DEFAULT NULL, disk_min DOUBLE PRECISION DEFAULT NULL, disk_avg DOUBLE PRECISION DEFAULT NULL, disk_max DOUBLE PRECISION DEFAULT NULL, INDEX IDX_2EBBB4A73414710B (agent_id), INDEX idx_metric_aggregate_bucket_start (bucket, bucket_start), UNIQUE INDEX uniq_metric_aggregate_bucket (agent_id, bucket, bucket_start), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE metric_aggregates ADD CONSTRAINT FK_2EBBB4A73414710B FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('metric_aggregates')) {
            return;
        }

        $this->addSql('ALTER TABLE metric_aggregates DROP FOREIGN KEY FK_2EBBB4A73414710B');
        $this->addSql('DROP TABLE metric_aggregates');
    }
}
