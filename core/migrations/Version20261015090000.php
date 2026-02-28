<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CMS template manager model: site-webspace binding, templates, and template versions.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('sites')) {
            $sites = $schema->getTable('sites');
            if (!$sites->hasColumn('cms_webspace_id')) {
                $this->addSql('ALTER TABLE sites ADD cms_webspace_id INT DEFAULT NULL');
                $this->addSql('ALTER TABLE sites ADD CONSTRAINT FK_SITES_CMS_WEBSPACE FOREIGN KEY (cms_webspace_id) REFERENCES webspaces (id) ON DELETE SET NULL');
                $this->addSql('CREATE INDEX IDX_SITES_CMS_WEBSPACE ON sites (cms_webspace_id)');
            }
        }

        if (!$schema->hasTable('cms_templates')) {
            $this->addSql('CREATE TABLE cms_templates (id INT AUTO_INCREMENT NOT NULL, template_key VARCHAR(64) NOT NULL, name VARCHAR(160) NOT NULL, active TINYINT(1) NOT NULL, preview_path VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_cms_templates_template_key (template_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$schema->hasTable('cms_template_versions')) {
            $this->addSql('CREATE TABLE cms_template_versions (id INT AUTO_INCREMENT NOT NULL, template_id INT NOT NULL, version_number INT NOT NULL, storage_path VARCHAR(255) NOT NULL, checksum VARCHAR(64) NOT NULL, manifest JSON NOT NULL, active TINYINT(1) NOT NULL, deployed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_CMS_TEMPLATE_VERSION_TEMPLATE (template_id), UNIQUE INDEX uniq_template_version (template_id, version_number), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE cms_template_versions ADD CONSTRAINT FK_CMS_TEMPLATE_VERSION_TEMPLATE FOREIGN KEY (template_id) REFERENCES cms_templates (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('cms_template_versions')) {
            $this->addSql('DROP TABLE cms_template_versions');
        }

        if ($schema->hasTable('cms_templates')) {
            $this->addSql('DROP TABLE cms_templates');
        }

        if ($schema->hasTable('sites')) {
            $sites = $schema->getTable('sites');
            if ($sites->hasColumn('cms_webspace_id')) {
                $this->addSql('ALTER TABLE sites DROP FOREIGN KEY FK_SITES_CMS_WEBSPACE');
                $this->addSql('DROP INDEX IDX_SITES_CMS_WEBSPACE ON sites');
                $this->addSql('ALTER TABLE sites DROP cms_webspace_id');
            }
        }
    }
}
