<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260502100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add impressum_content, datenschutz_content to cms_site_settings; add contact_messages table';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('cms_site_settings')) {
            $table = $schema->getTable('cms_site_settings');
            if (!$table->hasColumn('impressum_content')) {
                $this->addSql("ALTER TABLE cms_site_settings ADD impressum_content LONGTEXT DEFAULT NULL");
            }
            if (!$table->hasColumn('datenschutz_content')) {
                $this->addSql("ALTER TABLE cms_site_settings ADD datenschutz_content LONGTEXT DEFAULT NULL");
            }
        }

        if (!$schema->hasTable('contact_messages')) {
            $this->addSql("
                CREATE TABLE contact_messages (
                    id INT AUTO_INCREMENT NOT NULL,
                    site_id INT NOT NULL,
                    name VARCHAR(140) NOT NULL,
                    email VARCHAR(180) NOT NULL,
                    subject VARCHAR(255) NOT NULL,
                    message LONGTEXT NOT NULL,
                    ip_address VARCHAR(45) NOT NULL DEFAULT '',
                    status VARCHAR(20) NOT NULL DEFAULT 'new',
                    admin_reply LONGTEXT DEFAULT NULL,
                    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    replied_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                    INDEX idx_contact_messages_site_status (site_id, status),
                    INDEX idx_contact_messages_site_created (site_id, created_at),
                    INDEX idx_contact_messages_ip_created (ip_address, created_at),
                    PRIMARY KEY (id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            ");
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('cms_site_settings')) {
            $table = $schema->getTable('cms_site_settings');
            if ($table->hasColumn('impressum_content')) {
                $this->addSql("ALTER TABLE cms_site_settings DROP COLUMN impressum_content");
            }
            if ($table->hasColumn('datenschutz_content')) {
                $this->addSql("ALTER TABLE cms_site_settings DROP COLUMN datenschutz_content");
            }
        }

        if ($schema->hasTable('contact_messages')) {
            $this->addSql("DROP TABLE contact_messages");
        }
    }
}
