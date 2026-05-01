<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260302110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make domain webspace attachment optional and store domain capabilities for webspace/mail orchestration.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('domains')) {
            return;
        }

        $table = $schema->getTable('domains');

        if (!$table->hasColumn('capability_webspace')) {
            $this->addSql('ALTER TABLE domains ADD capability_webspace TINYINT(1) NOT NULL DEFAULT 1');
        }

        if (!$table->hasColumn('capability_mail')) {
            $this->addSql('ALTER TABLE domains ADD capability_mail TINYINT(1) NOT NULL DEFAULT 0');
        }

        if ($table->hasColumn('webspace_id')) {
            $this->addSql('ALTER TABLE domains CHANGE webspace_id webspace_id INT DEFAULT NULL');
        }

        $this->addSql('UPDATE domains SET capability_webspace = CASE WHEN webspace_id IS NULL THEN 0 ELSE 1 END');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('domains')) {
            return;
        }

        $table = $schema->getTable('domains');

        if ($table->hasColumn('webspace_id')) {
            $this->addSql('UPDATE domains SET webspace_id = 0 WHERE webspace_id IS NULL');
            $this->addSql('ALTER TABLE domains CHANGE webspace_id webspace_id INT NOT NULL');
        }

        if ($table->hasColumn('capability_webspace')) {
            $this->addSql('ALTER TABLE domains DROP capability_webspace');
        }

        if ($table->hasColumn('capability_mail')) {
            $this->addSql('ALTER TABLE domains DROP capability_mail');
        }
    }
}
