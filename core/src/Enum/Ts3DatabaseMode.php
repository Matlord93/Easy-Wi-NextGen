<?php

declare(strict_types=1);

namespace App\Enum;

enum Ts3DatabaseMode: string
{
    case Sqlite = 'sqlite';
    case Mysql = 'mysql';
}
