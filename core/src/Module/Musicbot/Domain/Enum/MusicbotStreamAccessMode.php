<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Enum;

enum MusicbotStreamAccessMode: string
{
    case Public = 'public';
    case Private = 'private';
    case Token = 'token';
}
