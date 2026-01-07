<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250218093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add changelog entries and knowledge base articles.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE changelog_entries (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, title VARCHAR(160) NOT NULL, version VARCHAR(80) DEFAULT NULL, content LONGTEXT NOT NULL, published_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', visible_public TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_changelog_entries_site_id (site_id), INDEX idx_changelog_entries_visibility (visible_public), INDEX idx_changelog_entries_published (published_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE knowledge_base_articles (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, title VARCHAR(160) NOT NULL, slug VARCHAR(160) NOT NULL, content LONGTEXT NOT NULL, category VARCHAR(255) NOT NULL, visible_public TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_knowledge_base_site_id (site_id), INDEX idx_knowledge_base_visibility (visible_public), INDEX idx_knowledge_base_category (category), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE knowledge_base_articles');
        $this->addSql('DROP TABLE changelog_entries');
    }
}
