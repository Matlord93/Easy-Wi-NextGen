<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260627140000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add instance-scoped musicbot plugin logs.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE musicbot_plugin_logs (id INT AUTO_INCREMENT NOT NULL, instance_id INT NOT NULL, customer_id INT NOT NULL, plugin_id VARCHAR(120) NOT NULL, event VARCHAR(80) NOT NULL, action VARCHAR(80) DEFAULT NULL, status VARCHAR(30) NOT NULL, message LONGTEXT NOT NULL, context JSON NOT NULL, created_at DATETIME NOT NULL, INDEX idx_musicbot_plugin_logs_instance (instance_id), INDEX idx_musicbot_plugin_logs_customer (customer_id), INDEX idx_musicbot_plugin_logs_plugin (plugin_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE musicbot_plugin_logs ADD CONSTRAINT fk_musicbot_plugin_logs_instance FOREIGN KEY (instance_id) REFERENCES musicbot_instances (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE musicbot_plugin_logs ADD CONSTRAINT fk_musicbot_plugin_logs_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS musicbot_plugin_logs');
    }
}
