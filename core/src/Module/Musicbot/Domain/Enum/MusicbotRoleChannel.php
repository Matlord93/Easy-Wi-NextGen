<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Enum;

enum MusicbotRoleChannel: string
{
    case Panel     = 'panel';
    case Api       = 'api';
    case Teamspeak = 'teamspeak';
    case Discord   = 'discord';

    public function label(): string
    {
        return match ($this) {
            self::Panel     => 'Web-Panel',
            self::Api       => 'API',
            self::Teamspeak => 'TeamSpeak',
            self::Discord   => 'Discord',
        };
    }
}
