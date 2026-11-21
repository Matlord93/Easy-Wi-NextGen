<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121120000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Link Proxmox rental lifecycle operations to the original installation.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $table = $schema->getTable('proxmox_tasks');
        $table->addColumn('origin_task_id', 'integer', ['notnull' => false]);
        $table->addIndex(['origin_task_id', 'action'], 'idx_pve_rental_origin_action');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Rental lifecycle jobs retain their installation identity.');
    }
}
