<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Enum;

enum MusicbotWorkflowActionType: string
{
    case StartPlayback = 'start_playback';
    case StopPlayback = 'stop_playback';
    case Pause = 'pause';
    case Resume = 'resume';
    case PlayPlaylist = 'play_playlist';
    case AddTrackToQueue = 'add_track_to_queue';
    case ClearQueue = 'clear_queue';
    case SetVolume = 'set_volume';
    case EnableShuffle = 'enable_shuffle';
    case SetRepeatMode = 'set_repeat_mode';
    case SendWebhook = 'send_webhook';
    case CreateRuntimeEvent = 'create_runtime_event';
    case TriggerAutoDj = 'trigger_autodj';
}
