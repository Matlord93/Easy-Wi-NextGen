<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112050000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Store the TLS certificate verification preference per Proxmox connection.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $schema->getTable('proxmox_clusters')->addColumn('verify_tls', 'boolean', ['default' => true]);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $schema->getTable('proxmox_clusters')->dropColumn('verify_tls');
    }
}
