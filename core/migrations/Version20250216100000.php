<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250216100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sites table and next_check_at for public server scheduling.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sites (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(160) NOT NULL, host VARCHAR(160) NOT NULL, allow_private_network_targets TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_9EBAF22B8D7673E9 (host), INDEX idx_sites_host (host), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('INSERT INTO sites (name, host, allow_private_network_targets, created_at, updated_at) VALUES (\'Default Site\', \'localhost\', 0, NOW(), NOW())');
        $this->addSql('ALTER TABLE public_servers ADD next_check_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public_servers DROP next_check_at');
        $this->addSql('DROP TABLE sites');
    }
}
