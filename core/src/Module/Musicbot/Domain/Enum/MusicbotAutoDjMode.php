<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Enum;

enum MusicbotAutoDjMode: string
{
    case Random = 'random';
    case Sequential = 'sequential';
    case ShufflePlaylist = 'shuffle_playlist';
    case PlaylistOrder = 'playlist_order';
}
