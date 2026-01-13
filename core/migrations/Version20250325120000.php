<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250325120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add name field to users for superadmin display name.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }

        $table = $schema->getTable('users');
        if (!$table->hasColumn('name')) {
            $this->addSql('ALTER TABLE users ADD name VARCHAR(120) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }

        $table = $schema->getTable('users');
        if ($table->hasColumn('name')) {
            $this->addSql('ALTER TABLE users DROP name');
        }
    }
}
