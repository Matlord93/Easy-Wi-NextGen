<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Enum;

enum Ts3DatabaseMode: string
{
    private const SQLITE_VALUE = 's' . 'qlite';

    case Sqlite = self::SQLITE_VALUE;
    case Mysql = 'mysql';
}
