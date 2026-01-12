<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250305090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CMS pages scoped to sites and seed legal templates.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cms_pages (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, title VARCHAR(160) NOT NULL, slug VARCHAR(160) NOT NULL, is_published TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_cms_pages_site_id (site_id), UNIQUE INDEX uniq_cms_pages_site_slug (site_id, slug), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE cms_blocks (id INT AUTO_INCREMENT NOT NULL, page_id INT NOT NULL, type VARCHAR(80) NOT NULL, content LONGTEXT NOT NULL, sort_order INT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_cms_blocks_page_id (page_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE cms_pages ADD CONSTRAINT FK_CMS_PAGES_SITE FOREIGN KEY (site_id) REFERENCES sites (id)');
        $this->addSql('ALTER TABLE cms_blocks ADD CONSTRAINT FK_CMS_BLOCKS_PAGE FOREIGN KEY (page_id) REFERENCES cms_pages (id)');

        $this->addSql(<<<'SQL'
INSERT INTO cms_pages (site_id, title, slug, is_published, created_at, updated_at)
SELECT id, 'Impressum', 'impressum', 1, NOW(), NOW() FROM sites
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO cms_pages (site_id, title, slug, is_published, created_at, updated_at)
SELECT id, 'Datenschutz', 'datenschutz', 1, NOW(), NOW() FROM sites
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO cms_pages (site_id, title, slug, is_published, created_at, updated_at)
SELECT id, 'AGB', 'agb', 1, NOW(), NOW() FROM sites
SQL);

        $this->addSql(<<<'SQL'
INSERT INTO cms_blocks (page_id, type, content, sort_order, created_at, updated_at)
SELECT id, 'text', '<h2>Angaben gemäß § 5 TMG</h2><p>[Firmenname]</p><p>[Straße Hausnummer]</p><p>[PLZ Ort]</p><p>Vertreten durch: [Geschäftsführer]</p><p>Kontakt: [Telefon] · [E-Mail]</p><p>USt-IdNr.: [USt-ID]</p>', 1, NOW(), NOW()
FROM cms_pages WHERE slug = 'impressum'
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO cms_blocks (page_id, type, content, sort_order, created_at, updated_at)
SELECT id, 'text', '<h2>Datenschutzerklärung</h2><p>Wir verarbeiten personenbezogene Daten gemäß DSGVO. Bitte ergänzen Sie die verantwortliche Stelle, Kontaktwege und Zwecke der Verarbeitung.</p><h3>Verantwortliche Stelle</h3><p>[Firmenname] · [Adresse] · [Kontakt]</p><h3>Betroffenenrechte</h3><p>Sie haben das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung und Datenübertragbarkeit.</p><h3>Speicherdauer</h3><p>[Speicherdauer / Kriterien]</p>', 1, NOW(), NOW()
FROM cms_pages WHERE slug = 'datenschutz'
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO cms_blocks (page_id, type, content, sort_order, created_at, updated_at)
SELECT id, 'text', '<h2>Allgemeine Geschäftsbedingungen</h2><p>Diese AGB gelten für alle Leistungen von [Firmenname]. Bitte ergänzen Sie Leistungsumfang, Zahlungsbedingungen und Kündigungsfristen.</p><h3>Leistungen</h3><p>[Beschreibung der Leistungen]</p><h3>Zahlung</h3><p>[Zahlungsbedingungen]</p><h3>Laufzeit & Kündigung</h3><p>[Laufzeit und Kündigungsregeln]</p>', 1, NOW(), NOW()
FROM cms_pages WHERE slug = 'agb'
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cms_blocks DROP FOREIGN KEY FK_CMS_BLOCKS_PAGE');
        $this->addSql('ALTER TABLE cms_pages DROP FOREIGN KEY FK_CMS_PAGES_SITE');
        $this->addSql('DROP TABLE cms_blocks');
        $this->addSql('DROP TABLE cms_pages');
    }
}
