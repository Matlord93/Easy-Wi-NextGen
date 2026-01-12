<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250323130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add TS6 and virtual server placeholder tables.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('ts6_instances')) {
            $this->addSql('CREATE TABLE ts6_instances (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, node_id VARCHAR(64) NOT NULL, name VARCHAR(80) NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_ts6_instances_customer_id (customer_id), INDEX idx_ts6_instances_node_id (node_id), INDEX idx_ts6_instances_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE ts6_instances ADD CONSTRAINT FK_TS6_INSTANCES_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
            $this->addSql('ALTER TABLE ts6_instances ADD CONSTRAINT FK_TS6_INSTANCES_NODE FOREIGN KEY (node_id) REFERENCES agents (id)');
        }

        if (!$schema->hasTable('ts_virtual_server')) {
            $this->addSql('CREATE TABLE ts_virtual_server (id INT AUTO_INCREMENT NOT NULL, ts6_instance_id INT NOT NULL, customer_id INT NOT NULL, name VARCHAR(80) NOT NULL, slots INT NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_ts_virtual_server_instance (ts6_instance_id), INDEX idx_ts_virtual_server_customer (customer_id), INDEX idx_ts_virtual_server_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE ts_virtual_server ADD CONSTRAINT FK_TS_VIRTUAL_SERVER_INSTANCE FOREIGN KEY (ts6_instance_id) REFERENCES ts6_instances (id)');
            $this->addSql('ALTER TABLE ts_virtual_server ADD CONSTRAINT FK_TS_VIRTUAL_SERVER_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('ts_virtual_server')) {
            $this->addSql('ALTER TABLE ts_virtual_server DROP FOREIGN KEY FK_TS_VIRTUAL_SERVER_INSTANCE');
            $this->addSql('ALTER TABLE ts_virtual_server DROP FOREIGN KEY FK_TS_VIRTUAL_SERVER_CUSTOMER');
            $this->addSql('DROP TABLE ts_virtual_server');
        }

        if ($schema->hasTable('ts6_instances')) {
            $this->addSql('ALTER TABLE ts6_instances DROP FOREIGN KEY FK_TS6_INSTANCES_CUSTOMER');
            $this->addSql('ALTER TABLE ts6_instances DROP FOREIGN KEY FK_TS6_INSTANCES_NODE');
            $this->addSql('DROP TABLE ts6_instances');
        }
    }
}
