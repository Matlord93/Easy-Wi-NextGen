<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260621100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Switch ts6_instances default update_channel from stable to beta (all TS6 releases are prerelease)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE ts6_instances SET update_channel = 'beta' WHERE update_channel = 'stable'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE ts6_instances SET update_channel = 'stable' WHERE update_channel = 'beta'");
    }
}
