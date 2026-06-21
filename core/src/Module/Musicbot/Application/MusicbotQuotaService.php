<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Exception\MusicbotQuotaExceededException;
use App\Repository\MusicbotConnectionRepository;
use App\Repository\MusicbotInstanceRepository;
use App\Repository\MusicbotPlaylistRepository;
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
        $count = count($this->playlistRepository->findByCustomer($customer));
        if ($count >= $limits->maxPlaylists) {
            throw new MusicbotQuotaExceededException(
                sprintf('Playlist limit reached (%d/%d). Delete some playlists to create more.', $count, $limits->maxPlaylists),
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
            if ($filePath !== null) {
                $absPath = $this->projectDir . '/' . $filePath;
                if (is_file($absPath)) {
                    $bytes += (int) filesize($absPath);
                }
            }
        }

        return $bytes;
    }
}
