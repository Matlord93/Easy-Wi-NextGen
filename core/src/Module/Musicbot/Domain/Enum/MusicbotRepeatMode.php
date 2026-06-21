<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Enum;

enum MusicbotRepeatMode: string
{
    case Off = 'off';
    case One = 'one';
    case All = 'all';
}
