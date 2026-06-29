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
use App\Module\Musicbot\Domain\Enum\MusicbotTrackSourceType;
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
        private readonly MusicbotQuotaService $quotaService,
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
        if (($data['enabled'] ?? false) === true) {
            $this->quotaService->assertAutoDjAllowed($customer);
        }

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
        foreach (['shuffle' => 'setShuffle', 'repeat' => 'setRepeat', 'allow_youtube' => 'setAllowYoutube', 'allow_uploads' => 'setAllowUploads', 'avoid_same_artist' => 'setAvoidSameArtist', 'avoidSameArtist' => 'setAvoidSameArtist'] as $key => $setter) {
            if (array_key_exists($key, $data)) {
                if ($key === 'allow_youtube' && (bool) $data[$key]) {
                    $this->quotaService->assertYoutubeAllowed($customer);
                }
                $settings->{$setter}((bool) $data[$key]);
            }
        }
        foreach (['idle_seconds' => 'setIdleSeconds', 'volume_override' => 'setVolumeOverride', 'repeat_protection_window' => 'setRepeatProtectionWindow'] as $key => $setter) {
            if (array_key_exists($key, $data)) {
                if ($key === 'volume_override') {
                    $settings->{$setter}($data[$key] !== null && $data[$key] !== '' ? (int) $data[$key] : null);
                    continue;
                }
                $settings->{$setter}((int) $data[$key]);
            }
        }
        foreach (['time_window_start' => 'setTimeWindowStart', 'time_window_end' => 'setTimeWindowEnd', 'webradio_fallback_url' => 'setWebradioFallbackUrl'] as $key => $setter) {
            if (array_key_exists($key, $data)) {
                if ($key === 'webradio_fallback_url' && trim((string) $data[$key]) !== '') {
                    $this->quotaService->assertWebradioAllowed($customer);
                }
                $settings->{$setter}($data[$key] !== null ? (string) $data[$key] : null);
            }
        }

        if (array_key_exists('min_queue_size', $data)) {
            $settings->setMinQueueSize((int) $data['min_queue_size']);
        }

        if (array_key_exists('genre_filter', $data)) {
            $settings->setGenreFilter($data['genre_filter'] !== null ? (string) $data['genre_filter'] : null);
        }

        if (array_key_exists('playlist_ids', $data)) {
            $playlistIds = [];
            foreach ((array) $data['playlist_ids'] as $playlistId) {
                $playlist = $this->playlistRepository->findOneForCustomer((int) $playlistId, $customer);
                if (!$playlist instanceof MusicbotPlaylist) {
                    throw new \InvalidArgumentException('Auto-DJ playlist not found or does not belong to you.');
                }
                $playlistIds[] = (int) $playlistId;
            }
            if ($playlistIds !== []) {
                $this->assertPlaylistsAllowed($customer);
            }
            $settings->setPlaylistIds($playlistIds);
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
            if ($playlist instanceof MusicbotPlaylist && $settings->getPlaylistIds() === []) {
                $settings->setPlaylistIds([(int) $playlist->getId()]);
            }
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
        $this->quotaService->assertAutoDjAllowed($customer);

        $settings = $this->getOrCreateSettings($instance);
        if (!$settings->isEnabled()) {
            $this->runtimeEventService->record($instance, 'autodj.skipped', 'info', 'Auto-DJ skipped because it is disabled.');
            return 0;
        }
        $added = $this->doFill($settings, $instance, true);

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

        return $this->doFill($settings, $instance, false);
    }

    /** @return array<string, mixed> */
    public function normalize(MusicbotAutoDjSettings $settings): array
    {
        $playlist = $settings->getFallbackPlaylist();
        $payload = $settings->getInstance()->getRuntimePayload() ?? [];
        $autoDjPayload = is_array($payload['autodj'] ?? null) ? $payload['autodj'] : [];

        return [
            'id' => $settings->getId(),
            'instance_id' => $settings->getInstance()->getId(),
            'enabled' => $settings->isEnabled(),
            'mode' => $settings->getMode()->value,
            'avoid_repeats' => $settings->isAvoidRepeats(),
            'min_queue_size' => $settings->getMinQueueSize(),
            'shuffle' => $settings->isShuffle(),
            'repeat' => $settings->isRepeat(),
            'idle_seconds' => $settings->getIdleSeconds(),
            'volume_override' => $settings->getVolumeOverride(),
            'time_window_start' => $settings->getTimeWindowStart(),
            'time_window_end' => $settings->getTimeWindowEnd(),
            'webradio_fallback_url' => $settings->getWebradioFallbackUrl(),
            'allow_youtube' => $settings->isAllowYoutube(),
            'allow_uploads' => $settings->isAllowUploads(),
            'repeat_protection_window' => $settings->getRepeatProtectionWindow(),
            'avoid_same_artist' => $settings->isAvoidSameArtist(),
            'playlist_ids' => $settings->getPlaylistIds(),
            'next_trigger_hint' => $settings->isEnabled() ? sprintf('Queue below %d items after %d idle second(s)', $settings->getMinQueueSize(), $settings->getIdleSeconds()) : null,
            'last_action' => $autoDjPayload['last_action'] ?? null,
            'last_run_at' => $autoDjPayload['last_run_at'] ?? null,
            'genre_filter' => $settings->getGenreFilter(),
            'fallback_playlist_id' => $playlist?->getId(),
            'fallback_playlist_name' => $playlist?->getName(),
            'recent_track_count' => count($settings->getLastPlayedTrackIds()),
            'created_at' => $settings->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $settings->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function doFill(MusicbotAutoDjSettings $settings, MusicbotInstance $instance, bool $manual): int
    {
        $customer = $instance->getCustomer();
        $this->quotaService->assertAutoDjAllowed($customer);
        if (!$this->isInsideTimeWindow($settings)) {
            $this->runtimeEventService->record($instance, 'autodj.skipped', 'info', 'Auto-DJ skipped because the current time is outside the configured time window.');
            return 0;
        }
        $queue = $this->queueItemRepository->findQueueForInstanceOrdered($instance);
        $currentSize = count($queue);
        if (!$manual && !$this->playbackAllowsAutoDj($instance, $currentSize, $settings)) {
            return 0;
        }
        $needed = $settings->getMinQueueSize() - $currentSize;

        if ($needed <= 0) {
            return 0;
        }

        $tracks = $this->selectTracks($settings, $instance, $needed);
        if ($tracks === [] && $settings->getWebradioFallbackUrl() !== null) {
            $this->quotaService->assertWebradioAllowed($customer);
            $tracks = [$this->queueService->createTrackForCustomer(
                $customer,
                'AutoDJ Webradio Fallback',
                MusicbotTrackSourceType::Webradio,
                'audio/mpeg',
                hash('sha256', 'autodj-webradio:' . $settings->getWebradioFallbackUrl()),
                0,
                $instance,
                null,
                null,
                ['stream_url' => $settings->getWebradioFallbackUrl(), 'autodj_fallback' => true],
            )];
        }

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
                $this->queueService->addTrackToQueue($customer, $instance, $track, $customer);
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
            $settings->setLastPlayedTrackIds(array_slice($recentIds, 0, max($settings->getRepeatProtectionWindow(), self::RECENT_TRACK_WINDOW)));
            $payload = $instance->getRuntimePayload() ?? [];
            $payload['autodj'] = array_merge($payload['autodj'] ?? [], ['last_action' => 'filled_queue', 'last_added' => $added, 'last_run_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)]);
            if ($settings->getVolumeOverride() !== null) { $payload['playback'] = array_merge($payload['playback'] ?? [], ['volume' => $settings->getVolumeOverride(), 'desired_volume' => $settings->getVolumeOverride()]); }
            if ($settings->isRepeat()) { $payload['playback'] = array_merge($payload['playback'] ?? [], ['repeat_mode' => 'all']); }
            $instance->setRuntimePayload($payload);
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

        return match ($settings->getMode()) {
            MusicbotAutoDjMode::Random, MusicbotAutoDjMode::ShufflePlaylist => $settings->isShuffle() ? $this->pickRandom($pool, $needed) : array_slice($pool, 0, $needed),
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
        $tracks = [];
        $playlistIds = $settings->getPlaylistIds();
        $fallbackPlaylist = $settings->getFallbackPlaylist();
        if ($fallbackPlaylist instanceof MusicbotPlaylist && $fallbackPlaylist->getId() !== null && !in_array($fallbackPlaylist->getId(), $playlistIds, true)) {
            $playlistIds[] = $fallbackPlaylist->getId();
        }
        if ($playlistIds !== []) {
            $this->assertPlaylistsAllowed($instance->getCustomer());
            foreach ($playlistIds as $playlistId) {
                $playlist = $this->playlistRepository->findOneForCustomer($playlistId, $instance->getCustomer());
                if ($playlist instanceof MusicbotPlaylist) {
                    foreach ($this->playlistItemRepository->findByPlaylistOrdered($playlist) as $item) {
                        $tracks[] = $item->getTrack();
                    }
                }
            }
        } elseif ($fallbackPlaylist instanceof MusicbotPlaylist) {
            $items = $this->playlistItemRepository->findByPlaylistOrdered($fallbackPlaylist);
            $tracks = array_map(static fn ($item): MusicbotTrack => $item->getTrack(), $items);
        } else {
            $tracks = $this->trackRepository->findByCustomer($instance->getCustomer());
        }
        $tracks = $this->filterAllowedSources($settings, $tracks);

        // Optional genre filter: checks metadata['genre']
        $genreFilter = $settings->getGenreFilter();
        if ($genreFilter !== null) {
            $filterLower = strtolower($genreFilter);
            $tracks = array_values(array_filter(
                $tracks,
                static fn (MusicbotTrack $t): bool => str_contains(strtolower((string) ($t->getMetadata()['genre'] ?? '')), $filterLower),
            ));
        }

        return $this->filterRepeatProtection($settings, $tracks);
    }


    /** @param MusicbotTrack[] $tracks @return MusicbotTrack[] */
    private function filterAllowedSources(MusicbotAutoDjSettings $settings, array $tracks): array
    {
        return array_values(array_filter($tracks, function (MusicbotTrack $track) use ($settings): bool {
            return match ($track->getSourceType()) {
                MusicbotTrackSourceType::Upload => $settings->isAllowUploads(),
                MusicbotTrackSourceType::Youtube => $settings->isAllowYoutube(),
                default => true,
            };
        }));
    }

    /** @param MusicbotTrack[] $tracks @return MusicbotTrack[] */
    private function filterRepeatProtection(MusicbotAutoDjSettings $settings, array $tracks): array
    {
        if (!$settings->isAvoidRepeats()) { return $tracks; }
        $recentIds = array_flip(array_slice($settings->getLastPlayedTrackIds(), 0, $settings->getRepeatProtectionWindow()));
        $recentArtists = [];
        if ($settings->isAvoidSameArtist()) {
            foreach ($tracks as $track) {
                if (isset($recentIds[$track->getId()]) && $track->getArtist() !== null) { $recentArtists[strtolower($track->getArtist())] = true; }
            }
        }
        $filtered = array_values(array_filter($tracks, static function (MusicbotTrack $track) use ($recentIds, $recentArtists, $settings): bool {
            if (isset($recentIds[$track->getId()])) { return false; }
            return !$settings->isAvoidSameArtist() || $track->getArtist() === null || !isset($recentArtists[strtolower($track->getArtist())]);
        }));
        return $filtered !== [] ? $filtered : $tracks;
    }

    private function playbackAllowsAutoDj(MusicbotInstance $instance, int $currentSize, MusicbotAutoDjSettings $settings): bool
    {
        if ($currentSize >= $settings->getMinQueueSize()) { return false; }
        $payload = $instance->getRuntimePayload() ?? [];
        $status = strtolower((string) (($payload['playback_status']['playback_state'] ?? $payload['playback']['state'] ?? $instance->getStatus()->value)));
        return in_array($status, ['idle', 'stopped', 'stop', 'offline', 'unknown'], true) || $currentSize === 0;
    }

    private function isInsideTimeWindow(MusicbotAutoDjSettings $settings): bool
    {
        $start = $settings->getTimeWindowStart(); $end = $settings->getTimeWindowEnd();
        if ($start === null || $end === null) { return true; }
        $now = (new \DateTimeImmutable())->format('H:i');
        return $start <= $end ? ($now >= $start && $now <= $end) : ($now >= $start || $now <= $end);
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

    private function assertPlaylistsAllowed(User $customer): void
    {
        $limits = $this->quotaService->usageForCustomer($customer)['limits'] ?? [];
        if (($limits['playlists_allowed'] ?? true) === false) {
            throw new \RuntimeException('Playlists are not available in your current plan.');
        }
    }

    private function assertOwnership(User $customer, MusicbotInstance $instance): void
    {
        if ($instance->getCustomer()->getId() !== $customer->getId()) {
            throw new \RuntimeException('Musicbot instance does not belong to the current customer.');
        }
    }
}
