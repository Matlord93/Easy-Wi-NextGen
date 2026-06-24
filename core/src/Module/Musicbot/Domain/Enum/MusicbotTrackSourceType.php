<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Enum;

enum MusicbotTrackSourceType: string
{
    case Upload = 'upload';
    case Stream = 'stream';
    case Url = 'url';
    case Plugin = 'plugin';
    case Webradio = 'webradio';
    case Youtube = 'youtube';
}
