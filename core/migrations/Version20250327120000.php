<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250327120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add port ranges for node management.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE port_ranges (id INT AUTO_INCREMENT NOT NULL, node_id VARCHAR(64) NOT NULL, purpose VARCHAR(120) NOT NULL, protocol VARCHAR(8) NOT NULL, start_port INT NOT NULL, end_port INT NOT NULL, enabled TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_port_ranges_node_id (node_id), INDEX idx_port_ranges_protocol (protocol), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE port_ranges ADD CONSTRAINT FK_PORT_RANGES_NODE FOREIGN KEY (node_id) REFERENCES agents (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE port_ranges DROP FOREIGN KEY FK_PORT_RANGES_NODE');
        $this->addSql('DROP TABLE port_ranges');
    }
}
