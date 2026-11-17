<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class JsonDefaultsSchemaListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        if (!JsonDefaultsSchemaManagerFactory::supports($event->getEntityManager()->getConnection())) {
            return;
        }
        $tables = [];
        foreach ($event->getSchema()->getTables() as $table) {
            $columns = [];
            foreach ($table->getColumns() as $column) {
                $default = $column->getTypeName() === 'json' ? EmptyJsonDefault::fromValue($column->getDefault()) : null;
                $columns[] = $default === null ? $column : $column->edit()->setDefaultValue($default)->create();
            }
            $tables[] = $table->edit()->setColumns(...$columns)->create();
        }
        $event->setSchema($event->getSchema()->edit()->setTables(...$tables)->create());
    }
}
