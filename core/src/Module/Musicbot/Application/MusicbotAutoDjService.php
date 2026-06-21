<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotAutoDjSettings;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotPlaylist;
use App\Module\Musicbot\Domain\Entity\MusicbotTrack;
use App\Module\Musicbot\Domain\Enum\MusicbotAutoDjMode;
use App\Repository\MusicbotAutoDjSettingsRepository;
use App\Repository\MusicbotPlaylistItemRepository;
use App\Repository\MusicbotPlaylistRepository;
use App\Repository\MusicbotQueueItemRepository;
use App\Repository\MusicbotTrackRepository;
use Doctrine\ORM\EntityManagerInterface;

final class MusicbotAutoDjService
{
    private const RECENT_TRACK_WINDOW = 20;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MusicbotAutoDjSettingsRepository $settingsRepository,
        private readonly MusicbotQueueService $queueService,
        private readonly MusicbotQueueItemRepository $queueItemRepository,
        private readonly MusicbotTrackRepository $trackRepository,
        private readonly MusicbotPlaylistRepository $playlistRepository,
        private readonly MusicbotPlaylistItemRepository $playlistItemRepository,
        private readonly MusicbotRuntimeEventService $runtimeEventService,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Returns the Auto-DJ settings for an instance, creating defaults if they don't exist yet.
     * Does NOT persist — call saveSettings() to persist.
     */
    public function getOrCreateSettings(MusicbotInstance $instance): MusicbotAutoDjSettings
    {
        return $this->settingsRepository->findByInstance($instance)
            ?? new MusicbotAutoDjSettings($instance->getCustomer(), $instance);
    }

    /**
     * Persists or updates Auto-DJ settings from an array of user-supplied data.
     *
     * @param array<string, mixed> $data
     */
    public function saveSettings(User $customer, MusicbotInstance $instance, array $data): MusicbotAutoDjSettings
    {
        $this->assertOwnership($customer, $instance);

        $settings = $this->settingsRepository->findByInstance($instance);
        if ($settings === null) {
            $settings = new MusicbotAutoDjSettings($customer, $instance);
            $this->entityManager->persist($settings);
        }

        $wasEnabled = $settings->isEnabled();

        if (array_key_exists('enabled', $data)) {
            $settings->setEnabled((bool) $data['enabled']);
        }

        if (array_key_exists('mode', $data)) {
            $mode = MusicbotAutoDjMode::tryFrom((string) $data['mode']);
            if ($mode === null) {
                throw new \InvalidArgumentException(sprintf('Invalid Auto-DJ mode: "%s".', $data['mode']));
            }
            $settings->setMode($mode);
        }

        if (array_key_exists('avoid_repeats', $data)) {
            $settings->setAvoidRepeats((bool) $data['avoid_repeats']);
        }

        if (array_key_exists('min_queue_size', $data)) {
            $settings->setMinQueueSize((int) $data['min_queue_size']);
        }

        if (array_key_exists('genre_filter', $data)) {
            $settings->setGenreFilter($data['genre_filter'] !== null ? (string) $data['genre_filter'] : null);
        }

        if (array_key_exists('fallback_playlist_id', $data)) {
            $playlist = null;
            if ($data['fallback_playlist_id'] !== null) {
                $playlist = $this->playlistRepository->findOneForCustomer((int) $data['fallback_playlist_id'], $customer);
                if ($playlist === null) {
                    throw new \InvalidArgumentException('Fallback playlist not found or does not belong to you.');
                }
            }
            $settings->setFallbackPlaylist($playlist);
        }

        $this->entityManager->flush();

        if ($wasEnabled !== $settings->isEnabled()) {
            $this->runtimeEventService->record(
                $instance,
                $settings->isEnabled() ? 'autodj.enabled' : 'autodj.disabled',
                'info',
                $settings->isEnabled() ? 'Auto-DJ enabled.' : 'Auto-DJ disabled.',
            );
        }

        $this->auditLogger->log($customer, 'musicbot.autodj_settings_saved', [
            'instance_id' => $instance->getId(),
            'enabled' => $settings->isEnabled(),
            'mode' => $settings->getMode()->value,
        ]);

        return $settings;
    }

    public function enable(User $customer, MusicbotInstance $instance): MusicbotAutoDjSettings
    {
        return $this->saveSettings($customer, $instance, ['enabled' => true]);
    }

    public function disable(User $customer, MusicbotInstance $instance): MusicbotAutoDjSettings
    {
        return $this->saveSettings($customer, $instance, ['enabled' => false]);
    }

    /**
     * Manual trigger — fills the queue regardless of the enabled flag, then records audit.
     * Returns the number of tracks added.
     */
    public function trigger(User $customer, MusicbotInstance $instance): int
    {
        $this->assertOwnership($customer, $instance);

        $settings = $this->getOrCreateSettings($instance);
        $added = $this->doFill($settings, $instance);

        $this->auditLogger->log($customer, 'musicbot.autodj_triggered', [
            'instance_id' => $instance->getId(),
            'tracks_added' => $added,
        ]);

        return $added;
    }

    /**
     * Internal trigger — used by the scheduler, workflow engine, and queue.empty events.
     * Respects the enabled flag; returns 0 if disabled.
     */
    public function fillQueueForInstance(MusicbotInstance $instance): int
    {
        $settings = $this->settingsRepository->findByInstance($instance);
        if ($settings === null || !$settings->isEnabled()) {
            $this->runtimeEventService->record(
                $instance,
                'autodj.skipped',
                'info',
                'Auto-DJ skipped because it is disabled.',
            );

            return 0;
        }

        return $this->doFill($settings, $instance);
    }

    /** @return array<string, mixed> */
    public function normalize(MusicbotAutoDjSettings $settings): array
    {
        $playlist = $settings->getFallbackPlaylist();

        return [
            'id' => $settings->getId(),
            'instance_id' => $settings->getInstance()->getId(),
            'enabled' => $settings->isEnabled(),
            'mode' => $settings->getMode()->value,
            'avoid_repeats' => $settings->isAvoidRepeats(),
            'min_queue_size' => $settings->getMinQueueSize(),
            'genre_filter' => $settings->getGenreFilter(),
            'fallback_playlist_id' => $playlist?->getId(),
            'fallback_playlist_name' => $playlist?->getName(),
            'recent_track_count' => count($settings->getLastPlayedTrackIds()),
            'created_at' => $settings->getCreatedAt()->format(DATE_ATOM),
            'updated_at' => $settings->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function doFill(MusicbotAutoDjSettings $settings, MusicbotInstance $instance): int
    {
        $customer = $instance->getCustomer();
        $currentSize = count($this->queueItemRepository->findQueueForInstanceOrdered($instance));
        $needed = $settings->getMinQueueSize() - $currentSize;

        if ($needed <= 0) {
            return 0;
        }

        $tracks = $this->selectTracks($settings, $instance, $needed);

        if ($tracks === []) {
            $this->runtimeEventService->record(
                $instance,
                'autodj.skipped',
                'warning',
                'Auto-DJ skipped: no eligible local tracks available to fill the queue.',
                ['mode' => $settings->getMode()->value, 'queue_size' => $currentSize, 'target' => $settings->getMinQueueSize()],
            );

            return 0;
        }

        $added = 0;
        $newIds = [];

        foreach ($tracks as $track) {
            try {
                $this->queueService->addTrackToQueue($customer, $instance, $track);
                $newIds[] = $track->getId();
                ++$added;
            } catch (\Throwable $e) {
                $this->runtimeEventService->record(
                    $instance,
                    'autodj.failed',
                    'error',
                    sprintf('Auto-DJ failed while filling the queue: %s', $e->getMessage()),
                    ['track_id' => $track->getId()],
                );
                break;
            }
        }

        if ($added > 0) {
            $recentIds = array_merge($newIds, $settings->getLastPlayedTrackIds());
            $settings->setLastPlayedTrackIds(array_slice($recentIds, 0, self::RECENT_TRACK_WINDOW));
            $this->entityManager->flush();

            $this->runtimeEventService->record(
                $instance,
                'autodj.filled_queue',
                'info',
                sprintf('Auto-DJ added %d track(s) to queue (was %d, target %d).', $added, $currentSize, $settings->getMinQueueSize()),
                ['added' => $added, 'mode' => $settings->getMode()->value],
            );
        }

        return $added;
    }

    /**
     * Selects up to $needed tracks from the pool, applying avoid-repeats and mode logic.
     *
     * @return MusicbotTrack[]
     */
    private function selectTracks(MusicbotAutoDjSettings $settings, MusicbotInstance $instance, int $needed): array
    {
        $pool = $this->buildPool($settings, $instance);

        if ($pool === []) {
            return [];
        }

        // Filter out recently played tracks if avoidRepeats is on
        if ($settings->isAvoidRepeats() && $settings->getLastPlayedTrackIds() !== []) {
            $recentIds = array_flip($settings->getLastPlayedTrackIds());
            $filtered = array_values(array_filter($pool, static fn (MusicbotTrack $t): bool => !isset($recentIds[$t->getId()])));

            // Only use filtered pool if it has enough tracks; fall back to full pool if empty
            if ($filtered !== []) {
                $pool = $filtered;
            }
        }

        return match ($settings->getMode()) {
            MusicbotAutoDjMode::Random, MusicbotAutoDjMode::ShufflePlaylist => $this->pickRandom($pool, $needed),
            MusicbotAutoDjMode::Sequential, MusicbotAutoDjMode::PlaylistOrder => array_slice($pool, 0, $needed),
        };
    }

    /**
     * Builds the candidate track pool from the fallback playlist or the customer's full library.
     *
     * @return MusicbotTrack[]
     */
    private function buildPool(MusicbotAutoDjSettings $settings, MusicbotInstance $instance): array
    {
        $fallbackPlaylist = $settings->getFallbackPlaylist();

        if ($fallbackPlaylist instanceof MusicbotPlaylist) {
            $items = $this->playlistItemRepository->findByPlaylistOrdered($fallbackPlaylist);
            $tracks = array_map(static fn ($item): MusicbotTrack => $item->getTrack(), $items);
        } else {
            $tracks = $this->trackRepository->findByCustomer($instance->getCustomer());
        }

        // Optional genre filter: checks metadata['genre']
        $genreFilter = $settings->getGenreFilter();
        if ($genreFilter !== null) {
            $filterLower = strtolower($genreFilter);
            $tracks = array_values(array_filter(
                $tracks,
                static fn (MusicbotTrack $t): bool => str_contains(strtolower((string) ($t->getMetadata()['genre'] ?? '')), $filterLower),
            ));
        }

        return $tracks;
    }

    /**
     * @param MusicbotTrack[] $pool
     * @return MusicbotTrack[]
     */
    private function pickRandom(array $pool, int $count): array
    {
        shuffle($pool);

        return array_slice($pool, 0, $count);
    }

    private function assertOwnership(User $customer, MusicbotInstance $instance): void
    {
        if ($instance->getCustomer()->getId() !== $customer->getId()) {
            throw new \RuntimeException('Musicbot instance does not belong to the current customer.');
        }
    }
}
