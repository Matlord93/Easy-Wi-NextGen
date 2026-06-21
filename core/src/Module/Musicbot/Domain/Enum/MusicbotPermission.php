<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Enum;

enum MusicbotPermission: string
{
    case View = 'musicbot.view';
    case Create = 'musicbot.create';
    case Update = 'musicbot.update';
    case Delete = 'musicbot.delete';
    case Start = 'musicbot.start';
    case Stop = 'musicbot.stop';
    case Restart = 'musicbot.restart';
    case PlaybackControl = 'musicbot.playback.control';
    case QueueManage = 'musicbot.queue.manage';
    case TracksUpload = 'musicbot.tracks.upload';
    case TracksDelete = 'musicbot.tracks.delete';
    case PlaylistsManage = 'musicbot.playlists.manage';
    case PluginsManage = 'musicbot.plugins.manage';
    case ConnectionsManage = 'musicbot.connections.manage';
    case LogsView = 'musicbot.logs.view';
    case SchedulesManage = 'musicbot.schedules.manage';
    case WorkflowsManage = 'musicbot.workflows.manage';
    case WebradioManage = 'musicbot.webradio.manage';

    /** @return self[] */
    public static function customerDefaults(): array
    {
        return [
            self::View,
            self::Start,
            self::Stop,
            self::Restart,
            self::PlaybackControl,
            self::QueueManage,
            self::TracksUpload,
            self::TracksDelete,
            self::PlaylistsManage,
            self::LogsView,
        ];
    }
}
