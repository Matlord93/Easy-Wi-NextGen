<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotPlaylist;
use App\Module\Musicbot\Domain\Entity\MusicbotPlaylistItem;
use App\Module\Musicbot\Domain\Entity\MusicbotQueueItem;
use App\Module\Musicbot\Domain\Entity\MusicbotRadioStation;
use App\Module\Musicbot\Domain\Entity\MusicbotTrack;
use App\Module\Musicbot\Domain\Enum\MusicbotTrackSourceType;
use App\Module\Musicbot\Domain\Exception\MusicbotQuotaExceededException;
use App\Repository\MusicbotPlaylistItemRepository;
use App\Repository\MusicbotPlaylistRepository;
use App\Repository\MusicbotQueueItemRepository;
use App\Repository\MusicbotRadioFavoriteRepository;
use App\Repository\MusicbotRadioStationRepository;
use App\Repository\MusicbotTrackRepository;
use Doctrine\ORM\EntityManagerInterface;

final class MusicbotRadioService
{
    private const MAX_STATIONS_PER_CUSTOMER = 200;

    public function __construct(
        private readonly MusicbotRadioStationRepository $stationRepository,
        private readonly MusicbotRadioFavoriteRepository $favoriteRepository,
        private readonly MusicbotQueueItemRepository $queueItemRepository,
        private readonly MusicbotPlaylistRepository $playlistRepository,
        private readonly MusicbotPlaylistItemRepository $playlistItemRepository,
        private readonly MusicbotTrackRepository $trackRepository,
        private readonly MusicbotRadioPlaylistResolver $resolver,
        private readonly MusicbotWebradioUrlValidator $urlValidator,
        private readonly MusicbotQuotaService $quotaService,
        private readonly MusicbotRadioCatalogService $catalogService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    // -------------------------------------------------------------------------
    // Customer private station CRUD
    // -------------------------------------------------------------------------

    /** @return MusicbotRadioStation[] */
    public function listForCustomer(User $customer): array
    {
        $this->assertWebradioAllowed($customer);

        return $this->stationRepository->findByCustomer($customer);
    }

    /** @return MusicbotRadioStation[] */
    public function favoritesForCustomer(User $customer): array
    {
        $this->assertWebradioAllowed($customer);

        return $this->stationRepository->findFavoritesByCustomer($customer);
    }

    /** @return MusicbotRadioStation[] */
    public function historyForCustomer(User $customer): array
    {
        $this->assertWebradioAllowed($customer);

        return $this->stationRepository->findRecentlyPlayed($customer, 20);
    }

    /**
     * Create a new private station for the customer. The stream URL is validated
     * and, if it is a playlist file, the resolved direct URL is cached.
     *
     * @param array{name: string, stream_url: string, genre?: string|null, description?: string|null, homepage?: string|null, logo_url?: string|null, country?: string|null, language?: string|null, tags?: string[], bitrate?: int|null, format?: string|null} $data
     */
    public function create(User $customer, MusicbotInstance $instance, array $data): MusicbotRadioStation
    {
        $this->assertWebradioAllowed($customer);
        $this->assertCustomerOwnsInstance($customer, $instance);

        if ($this->stationRepository->countByCustomer($customer) >= self::MAX_STATIONS_PER_CUSTOMER) {
            throw new MusicbotQuotaExceededException(sprintf('Radio station limit reached (%d). Delete some stations first.', self::MAX_STATIONS_PER_CUSTOMER));
        }

        $name = trim((string) ($data['name'] ?? ''));
        $streamUrl = trim((string) ($data['stream_url'] ?? ''));

        if ($name === '') {
            throw new \InvalidArgumentException('Station name must not be empty.');
        }

        $this->urlValidator->validate($streamUrl);

        $station = new MusicbotRadioStation($customer, $name, $streamUrl, false);
        $station->setInstance($instance);
        $station->setGenre(isset($data['genre']) && $data['genre'] !== '' ? (string) $data['genre'] : null);
        $station->setDescription(isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null);
        $station->setHomepage(isset($data['homepage']) && $data['homepage'] !== '' ? (string) $data['homepage'] : null);
        $station->setLogoUrl(isset($data['logo_url']) && $data['logo_url'] !== '' ? (string) $data['logo_url'] : null);
        $station->setCountry(isset($data['country']) && $data['country'] !== '' ? (string) $data['country'] : null);
        $station->setLanguage(isset($data['language']) && $data['language'] !== '' ? (string) $data['language'] : null);
        if (isset($data['tags']) && is_array($data['tags'])) {
            $station->setTags($data['tags']);
        }
        if (isset($data['bitrate'])) {
            $station->setBitrate((int) $data['bitrate'] ?: null);
        }
        if (isset($data['format'])) {
            $station->setFormat($data['format'] !== '' ? (string) $data['format'] : null);
        }

        // Try resolving the URL; cache the result but don't fail if unreachable yet
        try {
            $resolved = $this->resolver->resolve($streamUrl);
            if ($resolved !== $streamUrl) {
                $station->setResolvedStreamUrl($resolved);
            }
        } catch (\RuntimeException) {
            // Non-fatal – the runtime will try again at play time
        }

        $this->entityManager->persist($station);

        return $station;
    }

    /**
     * Update a customer's own private station.
     *
     * @param array<string, mixed> $data
     */
    public function update(MusicbotRadioStation $station, array $data): void
    {
        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new \InvalidArgumentException('Station name must not be empty.');
            }
            $station->setName($name);
        }

        if (isset($data['stream_url'])) {
            $this->urlValidator->validate((string) $data['stream_url']);
            $station->setStreamUrl((string) $data['stream_url']);
            $station->setResolvedStreamUrl(null); // invalidate cached resolution
        }

        foreach (['genre', 'description', 'homepage', 'logo_url', 'country', 'language', 'format'] as $field) {
            if (array_key_exists($field, $data)) {
                $setter = 'set' . str_replace('_', '', ucwords($field, '_'));
                $station->{$setter}(isset($data[$field]) && $data[$field] !== '' ? (string) $data[$field] : null);
            }
        }

        if (isset($data['tags']) && is_array($data['tags'])) {
            $station->setTags($data['tags']);
        }

        if (array_key_exists('bitrate', $data)) {
            $station->setBitrate(isset($data['bitrate']) ? ((int) $data['bitrate'] ?: null) : null);
        }

        if (isset($data['is_favorite'])) {
            $station->setFavorite((bool) $data['is_favorite']);
        }
    }

    public function delete(MusicbotRadioStation $station): void
    {
        $this->entityManager->remove($station);
    }

    // -------------------------------------------------------------------------
    // URL resolution
    // -------------------------------------------------------------------------

    /**
     * @return array{url: string, content_type: string|null, bitrate: int|null, stream_name: string|null, genre: string|null}
     */
    public function resolveUrl(User $customer, string $url): array
    {
        $this->assertWebradioAllowed($customer);

        return $this->resolver->resolveWithMetadata($url);
    }

    // -------------------------------------------------------------------------
    // Playback integration
    // -------------------------------------------------------------------------

    /**
     * Play a station now (works for both catalog and private stations).
     *
     * @return array<string, mixed>
     */
    public function playNow(User $customer, MusicbotInstance $instance, MusicbotRadioStation $station): array
    {
        $this->assertWebradioAllowed($customer);
        $this->assertAccessible($customer, $station);

        $resolvedUrl = $station->getResolvedStreamUrl() ?? $this->resolver->resolve($station->getStreamUrl());
        $station->setResolvedStreamUrl($resolvedUrl);

        $track = $this->ensureRadioTrack($customer, $instance, $station, $resolvedUrl);
        $this->catalogService->recordHistory($customer, $station, $instance);
        $this->entityManager->flush();

        return [
            'track_id'     => $track->getId(),
            'resolved_url' => $resolvedUrl,
            'station_id'   => $station->getId(),
            'reconnect'    => $this->buildReconnectPolicy(),
        ];
    }

    /**
     * Add a station to the queue.
     *
     * @return array<string, mixed>
     */
    public function addToQueue(User $customer, MusicbotInstance $instance, MusicbotRadioStation $station): array
    {
        $this->assertWebradioAllowed($customer);
        $this->assertAccessible($customer, $station);
        $this->quotaService->assertCanAddToQueue($customer, $instance);

        $resolvedUrl = $station->getResolvedStreamUrl() ?? $this->resolver->resolve($station->getStreamUrl());
        $station->setResolvedStreamUrl($resolvedUrl);

        $track = $this->ensureRadioTrack($customer, $instance, $station, $resolvedUrl);
        $queue = $this->queueItemRepository->findQueueForInstanceOrdered($instance);
        $position = count($queue) > 0 ? max(array_map(static fn (MusicbotQueueItem $i): int => $i->getPosition(), $queue)) + 1 : 1;
        $queueItem = new MusicbotQueueItem($instance, $track, $position, $customer);

        $this->catalogService->recordHistory($customer, $station, $instance);
        $this->entityManager->persist($queueItem);
        $this->entityManager->flush();

        return [
            'queue_item_id' => $queueItem->getId(),
            'track_id'      => $track->getId(),
            'resolved_url'  => $resolvedUrl,
            'station_id'    => $station->getId(),
        ];
    }

    /**
     * Add a station to an existing playlist.
     *
     * @return array<string, mixed>
     */
    public function addToPlaylist(User $customer, MusicbotInstance $instance, MusicbotRadioStation $station, int $playlistId): array
    {
        $this->assertWebradioAllowed($customer);
        $this->assertAccessible($customer, $station);

        $playlist = $this->playlistRepository->findOneForCustomer($playlistId, $customer);
        if (!$playlist instanceof MusicbotPlaylist) {
            throw new \RuntimeException('Playlist not found.');
        }

        $this->quotaService->assertCanAddPlaylistItem($customer, $playlist);

        $resolvedUrl = $station->getResolvedStreamUrl() ?? $this->resolver->resolve($station->getStreamUrl());
        $station->setResolvedStreamUrl($resolvedUrl);

        $track = $this->ensureRadioTrack($customer, $instance, $station, $resolvedUrl);
        $items = $this->playlistItemRepository->findByPlaylistOrdered($playlist);
        $position = count($items) > 0 ? max(array_map(static fn ($i): int => $i->getPosition(), $items)) + 1 : 1;

        $playlistItem = new MusicbotPlaylistItem($playlist, $track, $position);
        $this->catalogService->recordHistory($customer, $station, $instance);
        $this->entityManager->persist($playlistItem);
        $this->entityManager->flush();

        return [
            'playlist_item_id' => $playlistItem->getId(),
            'track_id'         => $track->getId(),
            'resolved_url'     => $resolvedUrl,
            'station_id'       => $station->getId(),
            'playlist_id'      => $playlist->getId(),
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Find a station accessible to the customer (global catalog OR own private station).
     * Returns null if not found.
     */
    public function findAccessible(int $id, User $customer): ?MusicbotRadioStation
    {
        $station = $this->stationRepository->find($id);
        if ($station === null) {
            return null;
        }
        if ($station->isGlobal()) {
            return $station;
        }
        if ($station->getCustomer()?->getId() === $customer->getId()) {
            return $station;
        }

        return null;
    }

    /** @return array{max_retries: int, retry_delay_seconds: int, backoff_multiplier: float} */
    public function buildReconnectPolicy(int $maxRetries = 5, int $retryDelaySeconds = 10): array
    {
        return [
            'max_retries'         => max(0, $maxRetries),
            'retry_delay_seconds' => max(1, $retryDelaySeconds),
            'backoff_multiplier'  => 1.5,
        ];
    }

    /**
     * @return array{id: int|null, name: string, stream_url: string, is_favorite: bool, last_played_at: string|null}
     */
    public function normalize(MusicbotRadioStation $station): array
    {
        return $this->catalogService->normalize($station);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function ensureRadioTrack(User $customer, MusicbotInstance $instance, MusicbotRadioStation $station, string $resolvedUrl): MusicbotTrack
    {
        $sha256 = hash('sha256', 'radio:' . $resolvedUrl);
        $existing = $this->trackRepository->findOneBy(['customer' => $customer, 'sha256' => $sha256]);
        if ($existing instanceof MusicbotTrack) {
            $existing->setTitle($station->getName());

            return $existing;
        }

        $track = new MusicbotTrack(
            $customer,
            $station->getName(),
            MusicbotTrackSourceType::Webradio,
            'audio/mpeg',
            $sha256,
            0,
            [
                'stream_url'   => $resolvedUrl,
                'original_url' => $station->getStreamUrl(),
                'station_id'   => $station->getId(),
                'genre'        => $station->getGenre(),
                'logo_url'     => $station->getLogoUrl(),
                'country'      => $station->getCountry(),
                'format'       => $station->getFormat(),
                'reconnect'    => $this->buildReconnectPolicy(),
            ],
        );
        $track->setInstance($instance);
        $this->entityManager->persist($track);

        return $track;
    }

    private function assertWebradioAllowed(User $customer): void
    {
        $this->quotaService->assertWebradioAllowed($customer);
    }

    private function assertCustomerOwnsInstance(User $customer, MusicbotInstance $instance): void
    {
        if ($instance->getCustomer()?->getId() !== $customer->getId()) {
            throw new \RuntimeException('Instance not found.');
        }
    }

    private function assertAccessible(User $customer, MusicbotRadioStation $station): void
    {
        if (!$station->isGlobal() && $station->getCustomer()?->getId() !== $customer->getId()) {
            throw new \RuntimeException('Radio station not found.');
        }
    }
}
