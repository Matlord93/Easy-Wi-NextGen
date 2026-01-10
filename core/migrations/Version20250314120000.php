<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250314120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add DDoS status table for agent reporting.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ddos_statuses (id INT AUTO_INCREMENT NOT NULL, node_id VARCHAR(64) NOT NULL, attack_active TINYINT(1) NOT NULL, packets_per_second INT DEFAULT NULL, connection_count INT DEFAULT NULL, ports JSON NOT NULL, protocols JSON NOT NULL, mode VARCHAR(20) DEFAULT NULL, reported_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_ddos_status_node (node_id), INDEX idx_ddos_status_node (node_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE ddos_statuses ADD CONSTRAINT FK_DDOS_STATUS_NODE FOREIGN KEY (node_id) REFERENCES agents (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ddos_statuses DROP FOREIGN KEY FK_DDOS_STATUS_NODE');
        $this->addSql('DROP TABLE ddos_statuses');
    }
}
