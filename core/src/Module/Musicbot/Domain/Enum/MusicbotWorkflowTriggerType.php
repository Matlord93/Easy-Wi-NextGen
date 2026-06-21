<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Enum;

enum MusicbotWorkflowTriggerType: string
{
    case ScheduleTime = 'schedule.time';
    case QueueEmpty = 'queue.empty';
    case TrackStarted = 'track.started';
    case TrackFinished = 'track.finished';
    case ConnectorConnected = 'connector.connected';
    case ConnectorDisconnected = 'connector.disconnected';
    case UserJoined = 'user.joined';
    case UserLeft = 'user.left';
    case PlaybackStopped = 'playback.stopped';
    case PluginEvent = 'plugin.event';
}
