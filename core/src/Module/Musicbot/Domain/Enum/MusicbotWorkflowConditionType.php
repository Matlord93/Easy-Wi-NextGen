<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Enum;

enum MusicbotWorkflowConditionType: string
{
    case PlatformIs = 'platform.is';
    case TeamspeakProfileIs = 'teamspeak_profile.is';
    case QueueLengthEquals = 'queue_length.equals';
    case QueueLengthGreater = 'queue_length.greater';
    case QueueLengthLess = 'queue_length.less';
    case TimeInRange = 'time.in_range';
    case UserCountGreater = 'user_count.greater';
    case UserCountLess = 'user_count.less';
    case PluginEnabled = 'plugin.enabled';
    case InstanceStatusIs = 'instance_status.is';
}
