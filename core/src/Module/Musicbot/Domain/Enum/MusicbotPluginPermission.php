<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Enum;

enum MusicbotPluginPermission: string
{
    case PlaybackControl = 'playback.control';
    case QueueManage = 'queue.manage';
    case PlaylistManage = 'playlist.manage';
    case TracksRead = 'tracks.read';
    case TracksWrite = 'tracks.write';
    case EventsSubscribe = 'events.subscribe';
    case CommandsRegister = 'commands.register';
    case PanelExtend = 'panel.extend';
    case ExternalHttp = 'external.http';

    /** @return string[] */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }
}
