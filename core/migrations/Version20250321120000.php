<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250321120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add job progress tracking.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('jobs') && !$schema->getTable('jobs')->hasColumn('progress')) {
            $this->addSql('ALTER TABLE jobs ADD progress INT DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('jobs') && $schema->getTable('jobs')->hasColumn('progress')) {
            $this->addSql('ALTER TABLE jobs DROP progress');
        }
    }
}
