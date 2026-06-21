<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Enum;

enum MusicbotScheduleAction: string
{
    case StartInstance = 'start_instance';
    case StopInstance = 'stop_instance';
    case RestartInstance = 'restart_instance';
    case PlayPlaylist = 'play_playlist';
    case ClearQueue = 'clear_queue';
    case SetVolume = 'set_volume';
    case EnableShuffle = 'enable_shuffle';
    case SetRepeatMode = 'set_repeat_mode';
    case StatusCheck = 'status_check';
    case EnableAutodj = 'enable_autodj';
    case DisableAutodj = 'disable_autodj';
}
