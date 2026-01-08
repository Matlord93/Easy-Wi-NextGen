<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250305120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CMS template selection to sites.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sites ADD cms_template_key VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sites DROP cms_template_key');
    }
}
