<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Enum;

enum MusicbotRoleSubjectType: string
{
    case PanelUser      = 'panel_user';
    case ApiToken       = 'api_token';
    case TeamspeakUid   = 'teamspeak_uid';
    case TeamspeakGroup = 'teamspeak_group';
    case DiscordUser    = 'discord_user';
    case DiscordRole    = 'discord_role';

    public function label(): string
    {
        return match ($this) {
            self::PanelUser      => 'Panel-Benutzer',
            self::ApiToken       => 'API-Token',
            self::TeamspeakUid   => 'TeamSpeak UID',
            self::TeamspeakGroup => 'TeamSpeak-Gruppe',
            self::DiscordUser    => 'Discord-Benutzer',
            self::DiscordRole    => 'Discord-Rolle',
        };
    }

    public function channel(): MusicbotRoleChannel
    {
        return match ($this) {
            self::PanelUser                  => MusicbotRoleChannel::Panel,
            self::ApiToken                   => MusicbotRoleChannel::Api,
            self::TeamspeakUid,
            self::TeamspeakGroup             => MusicbotRoleChannel::Teamspeak,
            self::DiscordUser,
            self::DiscordRole                => MusicbotRoleChannel::Discord,
        };
    }
}
