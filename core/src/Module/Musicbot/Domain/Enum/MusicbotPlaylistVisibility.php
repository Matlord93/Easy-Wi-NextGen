<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Enum;

enum MusicbotPlaylistVisibility: string
{
    case Private = 'private';
    case Customer = 'customer';
    case Public = 'public';
}
