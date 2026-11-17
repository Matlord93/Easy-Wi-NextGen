<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118010000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Store administrator-managed Agent/path/service bindings for complete module backups, encrypted at rest.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $table = $schema->createTable('backup_module_bindings');
        $table->addColumn('record_key', 'string', ['length' => 64]);
        $table->addColumn('payload', 'text', ['length' => 16777215]);
        $table->setPrimaryKey(['record_key']);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve encrypted module backup bindings.');
    }
}
