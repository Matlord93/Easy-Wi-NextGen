<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260601000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add last_heartbeat_ipv6 column to agent table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agents ADD last_heartbeat_ipv6 VARCHAR(45) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agents DROP COLUMN last_heartbeat_ipv6');
    }
}
