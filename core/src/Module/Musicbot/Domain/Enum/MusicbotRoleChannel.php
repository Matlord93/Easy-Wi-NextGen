<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Enum;

enum MusicbotRoleChannel: string
{
    case Panel     = 'panel';
    case Api       = 'api';
    case Teamspeak = 'teamspeak';
    case Discord   = 'discord';

}
