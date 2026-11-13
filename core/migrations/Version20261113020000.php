<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113020000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Archive validated XRechnung and ZUGFeRD documents immutably.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $table = $schema->createTable('electronic_invoice_archives');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('invoice_id', 'integer');
        $table->addColumn('format', 'string', ['length' => 12]);
        $table->addColumn('contents', 'blob', ['length' => 20000000]);
        $table->addColumn('sha256', 'string', ['length' => 64]);
        $table->addColumn('validation_version', 'string', ['length' => 160]);
        $table->addColumn('created_at', 'datetime_immutable');
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['invoice_id', 'format'], 'uniq_einvoice_format');
        $table->addIndex(['invoice_id']);
        $table->addForeignKeyConstraint('invoices', ['invoice_id'], ['id']);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve legally relevant invoice archives.');
    }
}
