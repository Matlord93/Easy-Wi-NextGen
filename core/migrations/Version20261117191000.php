<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117191000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Release solely instance-owned port allocations when their instance is deleted.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $table = $schema->getTable('port_allocations');
        foreach ($table->getForeignKeys() as $foreignKey) {
            $columns = array_map(static fn (UnqualifiedName $name): string => $name->toString(), $foreignKey->getReferencingColumnNames());
            if ($foreignKey->getReferencedTableName()->toString() !== 'instances' || $columns !== ['instance_id']) {
                continue;
            }
            $name = $foreignKey->getObjectName();
            if ($name === null) {
                throw new \RuntimeException('The instance ownership foreign key must have a name.');
            }
            $updated = $table->edit()->dropForeignKeyConstraint($name)
                ->addForeignKeyConstraint($foreignKey->edit()->setOnDeleteAction(ReferentialAction::CASCADE)->create())->create();
            $diff = $this->connection->createSchemaManager()->createComparator()->compareTables($table, $updated);
            foreach ($this->connection->getDatabasePlatform()->getAlterTableSQL($diff) as $sql) {
                $this->addSql($sql);
            }
            return;
        }
        throw new \RuntimeException('Expected instance ownership foreign key on port_allocations was not found.');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve instance allocation lifecycle semantics.');
    }
}
