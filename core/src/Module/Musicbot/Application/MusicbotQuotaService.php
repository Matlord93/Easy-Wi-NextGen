<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Exception\MusicbotQuotaExceededException;
use App\Repository\MusicbotConnectionRepository;
use App\Repository\MusicbotInstanceRepository;
use App\Repository\MusicbotPlaylistRepository;
use App\Repository\MusicbotPlaylistItemRepository;
use App\Repository\MusicbotPluginRepository;
use App\Repository\MusicbotQueueItemRepository;
use App\Repository\MusicbotTrackRepository;

final class MusicbotQuotaService implements MusicbotQuotaServiceInterface
{
    public function __construct(
        private readonly MusicbotPlanLimitResolver $limitResolver,
        private readonly MusicbotInstanceRepository $instanceRepository,
        private readonly MusicbotTrackRepository $trackRepository,
        private readonly MusicbotPlaylistRepository $playlistRepository,
        private readonly MusicbotPlaylistItemRepository $playlistItemRepository,
        private readonly MusicbotPluginRepository $pluginRepository,
        private readonly MusicbotQueueItemRepository $queueItemRepository,
        private readonly MusicbotConnectionRepository $connectionRepository,
        private readonly string $projectDir,
    ) {
    }

    public function assertCanCreateMusicbot(User $customer): void
    {
        $limits = $this->limitResolver->resolve($customer);
        $count = count($this->instanceRepository->findByCustomer($customer));
        if ($count >= $limits->maxMusicbots) {
            throw new MusicbotQuotaExceededException(
                sprintf('Musicbot limit reached (%d/%d). Please contact support to upgrade your plan.', $count, $limits->maxMusicbots),
            );
        }
    }

    public function assertCanUploadTrack(User $customer, int $fileSizeBytes): void
    {
        $limits = $this->limitResolver->resolve($customer);
        $tracks = $this->trackRepository->findByCustomer($customer);

        if (count($tracks) >= $limits->maxTracks) {
            throw new MusicbotQuotaExceededException(
                sprintf('Track limit reached (%d/%d). Delete some tracks to upload more.', count($tracks), $limits->maxTracks),
            );
        }

        $maxUploadBytes = $limits->maxUploadSizeMb * 1024 * 1024;
        if ($fileSizeBytes > $maxUploadBytes) {
            throw new MusicbotQuotaExceededException(
                sprintf('Upload size exceeds limit (%.1f MB / %d MB allowed per file).', $fileSizeBytes / 1024 / 1024, $limits->maxUploadSizeMb),
            );
        }

        $usedBytes = $this->computeStorageBytes($customer);
        $maxStorageBytes = $limits->maxStorageMb * 1024 * 1024;
        if ($usedBytes + $fileSizeBytes > $maxStorageBytes) {
            throw new MusicbotQuotaExceededException(
                sprintf('Storage limit reached (%.1f/%d MB). Delete some tracks to free up space.', $usedBytes / 1024 / 1024, $limits->maxStorageMb),
            );
        }
    }

    public function assertCanCreatePlaylist(User $customer): void
    {
        $limits = $this->limitResolver->resolve($customer);
        if (!$limits->allowPlaylists) {
            throw new MusicbotQuotaExceededException('Playlists are not available in your current plan.');
        }
        $count = count($this->playlistRepository->findByCustomer($customer));
        if ($count >= $limits->maxPlaylists) {
            throw new MusicbotQuotaExceededException(
                sprintf('Playlist limit reached (%d/%d). Delete some playlists to create more.', $count, $limits->maxPlaylists),
            );
        }
    }

    public function assertCanAddPlaylistItem(User $customer, \App\Module\Musicbot\Domain\Entity\MusicbotPlaylist $playlist): void
    {
        $limits = $this->limitResolver->resolve($customer);
        if (!$limits->allowPlaylists) {
            throw new MusicbotQuotaExceededException('Playlists are not available in your current plan.');
        }
        $count = count($this->playlistItemRepository->findByPlaylistOrdered($playlist));
        if ($count >= $limits->maxPlaylistItems) {
            throw new MusicbotQuotaExceededException(
                sprintf('Playlist item limit reached (%d/%d). Remove tracks from the playlist first.', $count, $limits->maxPlaylistItems),
            );
        }
    }

    public function assertCanAddToQueue(User $customer, MusicbotInstance $instance): void
    {
        $limits = $this->limitResolver->resolve($customer);
        $count = count($this->queueItemRepository->findQueueForInstanceOrdered($instance));
        if ($count >= $limits->maxQueueItems) {
            throw new MusicbotQuotaExceededException(
                sprintf('Queue limit reached (%d/%d items). Remove some items from the queue first.', $count, $limits->maxQueueItems),
            );
        }
    }

    public function assertCanAssignPlugin(User $customer): void
    {
        $limits = $this->limitResolver->resolve($customer);
        if (!$limits->allowPlugins) {
            throw new MusicbotQuotaExceededException('Plugin support is not available in your current plan.');
        }
        $count = count($this->pluginRepository->findByCustomer($customer));
        if ($count >= $limits->maxPlugins) {
            throw new MusicbotQuotaExceededException(
                sprintf('Plugin limit reached (%d/%d). Remove some plugins to assign more.', $count, $limits->maxPlugins),
            );
        }
    }

    public function assertCanManageTeamspeakConnection(User $customer): void
    {
        $limits = $this->limitResolver->resolve($customer);
        if (!$limits->allowTeamspeak) {
            throw new MusicbotQuotaExceededException('TeamSpeak connections are not available in your current plan.');
        }
    }

    public function assertTeamspeakCommandsAllowed(User $customer): void
    {
        $limits = $this->limitResolver->resolve($customer);
        if (!$limits->allowTeamspeakCommands) {
            throw new MusicbotQuotaExceededException('TeamSpeak commands are not available in your current plan.');
        }
    }

    public function assertCanUseTeamspeak6Profile(User $customer): void
    {
        $limits = $this->limitResolver->resolve($customer);
        if (!$limits->allowTeamspeak6Profile) {
            throw new MusicbotQuotaExceededException('TeamSpeak 6 profile is not available in your current plan.');
        }
    }

    public function assertCanManageDiscordConnection(User $customer): void
    {
        $limits = $this->limitResolver->resolve($customer);
        if (!$limits->allowDiscord) {
            throw new MusicbotQuotaExceededException('Discord connections are not available in your current plan.');
        }
    }

    public function assertConnectionCountAllowed(User $customer, MusicbotInstance $instance): void
    {
        $limits = $this->limitResolver->resolve($customer);
        $count = count($this->connectionRepository->findBy(['musicbotInstance' => $instance]));
        if ($count >= $limits->maxConnections) {
            throw new MusicbotQuotaExceededException(
                sprintf('Connection limit reached (%d/%d). Remove an existing connection first.', $count, $limits->maxConnections),
            );
        }
    }

    public function assertWebradioAllowed(User $customer): void
    {
        $limits = $this->limitResolver->resolve($customer);
        if (!$limits->allowWebradio) {
            throw new MusicbotQuotaExceededException('Web radio is not available in your current plan.');
        }
    }

    public function assertYoutubeAllowed(User $customer): void
    {
        $limits = $this->limitResolver->resolve($customer);
        if (!$limits->allowYoutube) {
            throw new MusicbotQuotaExceededException('YouTube playback is not available in your current plan.');
        }
    }

    public function assertAutoDjAllowed(User $customer): void
    {
        $limits = $this->limitResolver->resolve($customer);
        if (!$limits->allowAutoDj) {
            throw new MusicbotQuotaExceededException('Auto-DJ is not available in your current plan.');
        }
    }

    public function assertStreamAllowed(User $customer): void
    {
        $limits = $this->limitResolver->resolve($customer);
        if (!$limits->allowStream) {
            throw new MusicbotQuotaExceededException('Streaming is not available in your current plan.');
        }
    }

    public function assertApiAllowed(User $customer): void
    {
        $limits = $this->limitResolver->resolve($customer);
        if (!$limits->allowApi) {
            throw new MusicbotQuotaExceededException('Musicbot API access is not available in your current plan.');
        }
    }

    public function assertWorkflowsAllowed(User $customer): void
    {
        $limits = $this->limitResolver->resolve($customer);
        if (!$limits->allowWorkflows) {
            throw new MusicbotQuotaExceededException('Workflows are not available in your current plan.');
        }
    }

    public function assertSchedulerAllowed(User $customer): void
    {
        $limits = $this->limitResolver->resolve($customer);
        if (!$limits->allowScheduler) {
            throw new MusicbotQuotaExceededException('Scheduler is not available in your current plan.');
        }
    }

    /** @return array<string, mixed> */
    public function usageForCustomer(User $customer): array
    {
        $limits = $this->limitResolver->resolve($customer);
        $tracks = $this->trackRepository->findByCustomer($customer);
        $storageBytes = $this->computeStorageBytes($customer);

        return [
            'musicbots' => [
                'used' => count($this->instanceRepository->findByCustomer($customer)),
                'max' => $limits->maxMusicbots,
            ],
            'tracks' => [
                'used' => count($tracks),
                'max' => $limits->maxTracks,
            ],
            'storage_mb' => [
                'used' => round($storageBytes / 1024 / 1024, 2),
                'max' => $limits->maxStorageMb,
            ],
            'playlists' => [
                'used' => count($this->playlistRepository->findByCustomer($customer)),
                'max' => $limits->maxPlaylists,
            ],
            'playlist_items' => [
                'used' => array_sum(array_map(fn ($playlist): int => count($this->playlistItemRepository->findByPlaylistOrdered($playlist)), $this->playlistRepository->findByCustomer($customer))),
                'max' => $limits->maxPlaylistItems,
            ],
            'plugins' => [
                'used' => count($this->pluginRepository->findByCustomer($customer)),
                'max' => $limits->maxPlugins,
            ],
            'limits' => $limits->toArray(),
        ];
    }

    private function computeStorageBytes(User $customer): int
    {
        $bytes = 0;
        foreach ($this->trackRepository->findByCustomer($customer) as $track) {
            $filePath = $track->getFilePath();
            // filePath is stored as an absolute path by MusicbotTrackService::uploadTrack.
            if ($filePath !== null && $filePath !== '' && is_file($filePath)) {
                $bytes += (int) filesize($filePath);
            }
        }

        return $bytes;
    }
}
