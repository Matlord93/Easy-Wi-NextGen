<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Enum;

enum EngineType: string
{
    case Mysql = 'mysql';
    case Mariadb = 'mariadb';
    case Postgresql = 'postgresql';

    /** @return string[] */
    public static function values(): array
    {
        return array_map(static fn (self $engine): string => $engine->value, self::cases());
    }
}
