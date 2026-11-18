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

}
