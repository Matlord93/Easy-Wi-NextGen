<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Enum;

enum MusicbotPlatform: string
{
    case Teamspeak = 'teamspeak';
    case Discord = 'discord';
}
