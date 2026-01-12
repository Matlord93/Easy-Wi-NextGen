<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250218090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add download items for public downloads page.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE download_items (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, title VARCHAR(160) NOT NULL, description LONGTEXT DEFAULT NULL, url VARCHAR(255) NOT NULL, version VARCHAR(80) DEFAULT NULL, file_size VARCHAR(80) DEFAULT NULL, sort_order INT NOT NULL, visible_public TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_download_items_site_id (site_id), INDEX idx_download_items_visibility (visible_public), INDEX idx_download_items_sort (sort_order), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE download_items');
    }
}
