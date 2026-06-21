<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Enum;

enum MusicbotTeamspeakProfile: string
{
    case Ts3 = 'ts3';
    case Ts6 = 'ts6';

    public function label(): string
    {
        return match ($this) {
            self::Ts3 => 'TeamSpeak 3',
            self::Ts6 => 'TeamSpeak 6',
        };
    }
}
