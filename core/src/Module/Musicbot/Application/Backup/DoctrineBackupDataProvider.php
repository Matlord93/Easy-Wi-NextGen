<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application\Backup;

use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Repository\MusicbotAutoDjSettingsRepository;
use App\Repository\MusicbotConnectionRepositoryInterface;
use App\Repository\MusicbotCustomerLimitsRepository;
use App\Repository\MusicbotPlaylistItemRepositoryInterface;
use App\Repository\MusicbotPlaylistRepositoryInterface;
use App\Repository\MusicbotPluginLogRepository;
use App\Repository\MusicbotPluginRepositoryInterface;
use App\Repository\MusicbotQueueItemQueryRepositoryInterface;
use App\Repository\MusicbotRadioStationRepositoryInterface;
use App\Repository\MusicbotTrackQueryRepositoryInterface;

final class DoctrineBackupDataProvider implements MusicbotBackupDataProvider
{
    public function __construct(
        private readonly MusicbotConnectionRepositoryInterface $connectionRepository,
        private readonly MusicbotPlaylistRepositoryInterface $playlistRepository,
        private readonly MusicbotPlaylistItemRepositoryInterface $playlistItemRepository,
        private readonly MusicbotTrackQueryRepositoryInterface $trackRepository,
        private readonly MusicbotQueueItemQueryRepositoryInterface $queueItemRepository,
        private readonly MusicbotRadioStationRepositoryInterface $radioStationRepository,
        private readonly MusicbotAutoDjSettingsRepository $autoDjSettingsRepository,
        private readonly MusicbotPluginRepositoryInterface $pluginRepository,
        private readonly MusicbotPluginLogRepository $pluginLogRepository,
        private readonly MusicbotCustomerLimitsRepository $customerLimitsRepository,
    ) {
    }

    /** @return list<\App\Module\Musicbot\Domain\Entity\MusicbotConnection> */
    #[\Override]
    public function getConnections(MusicbotInstance $instance): array
    {
        return $this->connectionRepository->findBy(['musicbotInstance' => $instance]);
    }

    /** @return list<\App\Module\Musicbot\Domain\Entity\MusicbotPlaylist> */
    #[\Override]
    public function getPlaylists(MusicbotInstance $instance): array
    {
        return $this->playlistRepository->findBy(['instance' => $instance]);
    }

    /** @return list<\App\Module\Musicbot\Domain\Entity\MusicbotPlaylistItem> */
    #[\Override]
    public function getPlaylistItems(mixed $playlist): array
    {
        return $this->playlistItemRepository->findBy(['playlist' => $playlist], ['position' => 'ASC']);
    }

    /** @return list<\App\Module\Musicbot\Domain\Entity\MusicbotRadioStation> */
    #[\Override]
    public function getRadioStations(MusicbotInstance $instance): array
    {
        return $this->radioStationRepository->findBy(['instance' => $instance]);
    }

    #[\Override]
    public function getAutoDjSettings(MusicbotInstance $instance): mixed
    {
        return $this->autoDjSettingsRepository->findOneBy(['instance' => $instance]);
    }

    /** @return list<\App\Module\Musicbot\Domain\Entity\MusicbotPlugin> */
    #[\Override]
    public function getPlugins(MusicbotInstance $instance): array
    {
        return $this->pluginRepository->findBy(['instance' => $instance]);
    }

    /** @return list<\App\Module\Musicbot\Domain\Entity\MusicbotQueueItem> */
    #[\Override]
    public function getQueueItems(MusicbotInstance $instance): array
    {
        return $this->queueItemRepository->findBy(['instance' => $instance], ['position' => 'ASC']);
    }

    /** @return list<\App\Module\Musicbot\Domain\Entity\MusicbotTrack> */
    #[\Override]
    public function getTracks(MusicbotInstance $instance): array
    {
        return $this->trackRepository->findBy(['instance' => $instance]);
    }

    /** @return list<\App\Module\Musicbot\Domain\Entity\MusicbotPluginLog> */
    #[\Override]
    public function getPluginLogs(MusicbotInstance $instance): array
    {
        return $this->pluginLogRepository->findBy(['instance' => $instance], ['createdAt' => 'DESC'], 500);
    }

    #[\Override]
    public function getCustomerLimits(MusicbotInstance $instance): mixed
    {
        return $this->customerLimitsRepository->findOneBy(['customer' => $instance->getCustomer()]);
    }

    #[\Override]
    public function findTrackBySha256(MusicbotInstance $instance, string $sha256): mixed
    {
        return $this->trackRepository->findOneBy(['instance' => $instance, 'sha256' => $sha256]);
    }
}
