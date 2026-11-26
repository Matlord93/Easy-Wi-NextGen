<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123120000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Store encrypted, one-time root password handoffs for assigned virtual machines.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        foreach (['kvm_guest_assignments', 'proxmox_guest_assignments'] as $name) {
            $schema->getTable($name)->addColumn('root_password_handoff', 'json', ['notnull' => false]);
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        foreach (['kvm_guest_assignments', 'proxmox_guest_assignments'] as $name) {
            $schema->getTable($name)->dropColumn('root_password_handoff');
        }
    }
}
