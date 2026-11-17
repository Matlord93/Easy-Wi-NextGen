<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\MySQLSchemaManager;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class JsonDefaultsSchemaManager extends MySQLSchemaManager
{
    #[\Override]
    protected function _getPortableTableColumnDefinition(array $tableColumn): Column
    {
        $column = parent::_getPortableTableColumnDefinition($tableColumn);
        $default = $column->getTypeName() === 'json' ? EmptyJsonDefault::fromValue($column->getDefault()) : null;

        return $default === null ? $column : $column->edit()->setDefaultValue($default)->create();
    }
}
