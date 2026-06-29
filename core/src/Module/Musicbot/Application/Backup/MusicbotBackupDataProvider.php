<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application\Backup;

use App\Module\Musicbot\Domain\Entity\MusicbotInstance;

interface MusicbotBackupDataProvider
{
    /** @return list<mixed> */
    public function getConnections(MusicbotInstance $instance): array;

    /** @return list<mixed> */
    public function getPlaylists(MusicbotInstance $instance): array;

    /** @return list<array<string, mixed>> */
    public function getPlaylistItems(mixed $playlist): array;

    /** @return list<mixed> */
    public function getRadioStations(MusicbotInstance $instance): array;

    public function getAutoDjSettings(MusicbotInstance $instance): mixed;

    /** @return list<mixed> */
    public function getPlugins(MusicbotInstance $instance): array;

    /** @return list<mixed> */
    public function getQueueItems(MusicbotInstance $instance): array;

    /** @return list<mixed> */
    public function getTracks(MusicbotInstance $instance): array;

    /** @return list<mixed> */
    public function getPluginLogs(MusicbotInstance $instance): array;

    public function getCustomerLimits(MusicbotInstance $instance): mixed;

    public function findTrackBySha256(MusicbotInstance $instance, string $sha256): mixed;
}
