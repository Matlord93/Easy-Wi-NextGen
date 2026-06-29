<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application\Backup;

use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Repository\MusicbotAutoDjSettingsRepository;
use App\Repository\MusicbotConnectionRepository;
use App\Repository\MusicbotCustomerLimitsRepository;
use App\Repository\MusicbotPlaylistItemRepository;
use App\Repository\MusicbotPlaylistRepository;
use App\Repository\MusicbotPluginLogRepository;
use App\Repository\MusicbotPluginRepository;
use App\Repository\MusicbotQueueItemRepository;
use App\Repository\MusicbotRadioStationRepository;
use App\Repository\MusicbotTrackRepository;

final class DoctrineBackupDataProvider implements MusicbotBackupDataProvider
{
    public function __construct(
        private readonly MusicbotConnectionRepository $connectionRepository,
        private readonly MusicbotPlaylistRepository $playlistRepository,
        private readonly MusicbotPlaylistItemRepository $playlistItemRepository,
        private readonly MusicbotTrackRepository $trackRepository,
        private readonly MusicbotQueueItemRepository $queueItemRepository,
        private readonly MusicbotRadioStationRepository $radioStationRepository,
        private readonly MusicbotAutoDjSettingsRepository $autoDjSettingsRepository,
        private readonly MusicbotPluginRepository $pluginRepository,
        private readonly MusicbotPluginLogRepository $pluginLogRepository,
        private readonly MusicbotCustomerLimitsRepository $customerLimitsRepository,
    ) {
    }

    public function getConnections(MusicbotInstance $instance): array
    {
        return $this->connectionRepository->findBy(['musicbotInstance' => $instance]);
    }

    public function getPlaylists(MusicbotInstance $instance): array
    {
        return $this->playlistRepository->findBy(['instance' => $instance]);
    }

    public function getPlaylistItems(mixed $playlist): array
    {
        return $this->playlistItemRepository->findBy(['playlist' => $playlist], ['position' => 'ASC']);
    }

    public function getRadioStations(MusicbotInstance $instance): array
    {
        return $this->radioStationRepository->findBy(['instance' => $instance]);
    }

    public function getAutoDjSettings(MusicbotInstance $instance): mixed
    {
        return $this->autoDjSettingsRepository->findOneBy(['instance' => $instance]);
    }

    public function getPlugins(MusicbotInstance $instance): array
    {
        return $this->pluginRepository->findBy(['instance' => $instance]);
    }

    public function getQueueItems(MusicbotInstance $instance): array
    {
        return $this->queueItemRepository->findBy(['instance' => $instance], ['position' => 'ASC']);
    }

    public function getTracks(MusicbotInstance $instance): array
    {
        return $this->trackRepository->findBy(['instance' => $instance]);
    }

    public function getPluginLogs(MusicbotInstance $instance): array
    {
        return $this->pluginLogRepository->findBy(['instance' => $instance], ['createdAt' => 'DESC'], 500);
    }

    public function getCustomerLimits(MusicbotInstance $instance): mixed
    {
        return $this->customerLimitsRepository->findOneBy(['customer' => $instance->getCustomer()]);
    }

    public function findTrackBySha256(MusicbotInstance $instance, string $sha256): mixed
    {
        return $this->trackRepository->findOneBy(['instance' => $instance, 'sha256' => $sha256]);
    }
}
