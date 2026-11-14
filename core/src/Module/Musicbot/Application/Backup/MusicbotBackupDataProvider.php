<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application\Backup;

use App\Module\Musicbot\Domain\Entity\MusicbotConnection;
use App\Module\Musicbot\Domain\Entity\MusicbotPlaylist;
use App\Module\Musicbot\Domain\Entity\MusicbotPlaylistItem;
use App\Module\Musicbot\Domain\Entity\MusicbotRadioStation;
use App\Module\Musicbot\Domain\Entity\MusicbotPlugin;
use App\Module\Musicbot\Domain\Entity\MusicbotQueueItem;
use App\Module\Musicbot\Domain\Entity\MusicbotTrack;
use App\Module\Musicbot\Domain\Entity\MusicbotPluginLog;
use App\Module\Musicbot\Domain\Entity\MusicbotAutoDjSettings;
use App\Module\Musicbot\Domain\Entity\MusicbotCustomerLimits;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;

interface MusicbotBackupDataProvider
{
    /** @return MusicbotConnection[] */
    public function getConnections(MusicbotInstance $instance): array;

    /** @return MusicbotPlaylist[] */
    public function getPlaylists(MusicbotInstance $instance): array;

    /** @return MusicbotPlaylistItem[] */
    public function getPlaylistItems(mixed $playlist): array;

    /** @return MusicbotRadioStation[] */
    public function getRadioStations(MusicbotInstance $instance): array;

    /** @return MusicbotAutoDjSettings|null */
    public function getAutoDjSettings(MusicbotInstance $instance): mixed;

    /** @return MusicbotPlugin[] */
    public function getPlugins(MusicbotInstance $instance): array;

    /** @return MusicbotQueueItem[] */
    public function getQueueItems(MusicbotInstance $instance): array;

    /** @return MusicbotTrack[] */
    public function getTracks(MusicbotInstance $instance): array;

    /** @return MusicbotPluginLog[] */
    public function getPluginLogs(MusicbotInstance $instance): array;

    /** @return MusicbotCustomerLimits|null */
    public function getCustomerLimits(MusicbotInstance $instance): mixed;

    /** @return MusicbotTrack|null */
    public function findTrackBySha256(MusicbotInstance $instance, string $sha256): mixed;
}
