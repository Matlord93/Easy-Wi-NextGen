<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Separate Minecraft and game-specific port pools.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE port_pools ADD game_scope VARCHAR(160) NOT NULL DEFAULT 'all'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE port_pools DROP game_scope');
    }
}
