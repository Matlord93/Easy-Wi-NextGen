<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121130000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Allow a VM to retain a second reservation per address family for customer IP switching.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $leases = $schema->getTable('proxmox_ip_leases');
        $leases->addColumn('reservation_slot', 'integer', ['default' => 0]);
        $leases->addUniqueIndex(['task_id', 'family', 'reservation_slot'], 'uniq_pve_ip_lease_task_slot');
        $leases->dropIndex('uniq_pve_ip_lease_task_family');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('A VM or one of its backups may still use either reserved address.');
    }
}
