<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store upstream Minecraft checksums, release channels and build requirements.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE minecraft_versions_catalog ADD metadata JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE minecraft_versions_catalog DROP metadata');
    }
}
