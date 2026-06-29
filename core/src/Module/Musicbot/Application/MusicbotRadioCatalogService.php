<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotRadioFavorite;
use App\Module\Musicbot\Domain\Entity\MusicbotRadioHistory;
use App\Module\Musicbot\Domain\Entity\MusicbotRadioStation;
use App\Module\Musicbot\Domain\Exception\MusicbotQuotaExceededException;
use App\Repository\MusicbotRadioFavoriteRepository;
use App\Repository\MusicbotRadioHistoryRepository;
use App\Repository\MusicbotRadioStationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Manages the global webradio catalog, favorites and play history.
 */
final class MusicbotRadioCatalogService
{
    private const HISTORY_KEEP_ROWS = 100;

    public function __construct(
        private readonly MusicbotRadioStationRepository $stationRepository,
        private readonly MusicbotRadioFavoriteRepository $favoriteRepository,
        private readonly MusicbotRadioHistoryRepository $historyRepository,
        private readonly MusicbotQuotaService $quotaService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    // -------------------------------------------------------------------------
    // Search / browse
    // -------------------------------------------------------------------------

    /**
     * Search the global catalog with optional filters.
     *
     * @param array{query?: string, genre?: string, country?: string, language?: string, format?: string, min_bitrate?: int, max_bitrate?: int, active_only?: bool, limit?: int, offset?: int} $filters
     * @return array{stations: array<int, array<string, mixed>>, total: int, genres: string[], countries: string[]}
     */
    public function search(User $customer, array $filters = []): array
    {
        $this->assertWebradioAllowed($customer);
        $stations = $this->stationRepository->searchCatalog($filters);
        $total = $this->stationRepository->countCatalog($filters);
        $favoriteIds = $this->favoriteRepository->getFavoriteStationIds($customer);

        return [
            'stations' => array_map(fn (MusicbotRadioStation $s): array => $this->normalize($s, $favoriteIds), $stations),
            'total'    => $total,
            'genres'   => $this->stationRepository->getDistinctGenres(),
            'countries' => $this->stationRepository->getDistinctCountries(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function getPopular(User $customer, int $limit = 20): array
    {
        $this->assertWebradioAllowed($customer);
        $popularIds = array_keys($this->historyRepository->getPopularStationIds($limit));
        $stations = $this->stationRepository->findPopularCatalogStations($popularIds, $limit);
        $favoriteIds = $this->favoriteRepository->getFavoriteStationIds($customer);

        return array_map(fn (MusicbotRadioStation $s): array => $this->normalize($s, $favoriteIds), $stations);
    }

    /** @return array<int, array<string, mixed>> */
    public function getNewest(User $customer, int $limit = 20): array
    {
        $this->assertWebradioAllowed($customer);
        $stations = $this->stationRepository->findNewestCatalogStations($limit);
        $favoriteIds = $this->favoriteRepository->getFavoriteStationIds($customer);

        return array_map(fn (MusicbotRadioStation $s): array => $this->normalize($s, $favoriteIds), $stations);
    }

    // -------------------------------------------------------------------------
    // Favorites
    // -------------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    public function getFavorites(User $customer): array
    {
        $this->assertWebradioAllowed($customer);
        $favorites = $this->favoriteRepository->findByCustomer($customer);
        $favoriteIds = array_map(static fn (MusicbotRadioFavorite $f): int => (int) $f->getStation()->getId(), $favorites);

        return array_map(fn (MusicbotRadioFavorite $f): array => $this->normalize($f->getStation(), $favoriteIds), $favorites);
    }

    public function addFavorite(User $customer, MusicbotRadioStation $station): void
    {
        $this->assertWebradioAllowed($customer);
        if ($this->favoriteRepository->findOneByCustomerAndStation($customer, $station) !== null) {
            return;
        }
        $fav = new MusicbotRadioFavorite($customer, $station);
        $this->entityManager->persist($fav);
    }

    public function removeFavorite(User $customer, MusicbotRadioStation $station): void
    {
        $this->assertWebradioAllowed($customer);
        $fav = $this->favoriteRepository->findOneByCustomerAndStation($customer, $station);
        if ($fav !== null) {
            $this->entityManager->remove($fav);
        }
    }

    // -------------------------------------------------------------------------
    // History
    // -------------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    public function getHistory(User $customer, int $limit = 20): array
    {
        $this->assertWebradioAllowed($customer);
        $history = $this->historyRepository->findByCustomer($customer, $limit);
        $favoriteIds = $this->favoriteRepository->getFavoriteStationIds($customer);

        return array_map(fn (MusicbotRadioHistory $h): array => array_merge(
            $this->normalize($h->getStation(), $favoriteIds),
            ['played_at' => $h->getPlayedAt()->format(\DateTimeInterface::ATOM)],
        ), $history);
    }

    public function recordHistory(User $customer, MusicbotRadioStation $station, ?\App\Module\Musicbot\Domain\Entity\MusicbotInstance $instance = null): void
    {
        $entry = new MusicbotRadioHistory($customer, $station, $instance);
        $this->entityManager->persist($entry);
        $station->setLastPlayedAt(new \DateTimeImmutable());
        $this->historyRepository->pruneOld($customer, self::HISTORY_KEEP_ROWS);
    }

    // -------------------------------------------------------------------------
    // Admin catalog management
    // -------------------------------------------------------------------------

    /**
     * Promote a customer-submitted private station to the global catalog.
     */
    public function promoteToGlobal(MusicbotRadioStation $station): void
    {
        $station->setGlobal(true);
        $station->setActive(true);
    }

    public function markInactive(MusicbotRadioStation $station): void
    {
        $station->setActive(false);
        $station->setLastCheckedAt(new \DateTimeImmutable());
    }

    public function markActive(MusicbotRadioStation $station): void
    {
        $station->setActive(true);
        $station->setLastCheckedAt(new \DateTimeImmutable());
    }

    /** @return array<int, array<string, mixed>> */
    public function getPendingCustomerStations(): array
    {
        return array_map(fn (MusicbotRadioStation $s): array => $this->normalize($s, []), $this->stationRepository->findPendingCustomerStations());
    }

    /** @return array<int, array<string, mixed>> */
    public function getAllGlobal(bool $includeInactive = true): array
    {
        return array_map(fn (MusicbotRadioStation $s): array => $this->normalize($s, []), $this->stationRepository->findAllGlobal($includeInactive));
    }

    // -------------------------------------------------------------------------
    // Normalization
    // -------------------------------------------------------------------------

    /**
     * @param int[] $favoriteIds
     * @return array<string, mixed>
     */
    public function normalize(MusicbotRadioStation $s, array $favoriteIds = []): array
    {
        return [
            'id'                  => $s->getId(),
            'name'                => $s->getName(),
            'stream_url'          => $s->getStreamUrl(),
            'resolved_stream_url' => $s->getResolvedStreamUrl(),
            'genre'               => $s->getGenre(),
            'description'         => $s->getDescription(),
            'homepage'            => $s->getHomepage(),
            'logo_url'            => $s->getLogoUrl(),
            'country'             => $s->getCountry(),
            'language'            => $s->getLanguage(),
            'tags'                => $s->getTags(),
            'bitrate'             => $s->getBitrate(),
            'format'              => $s->getFormat(),
            'is_global'           => $s->isGlobal(),
            'is_active'           => $s->isActive(),
            'is_favorite'         => in_array($s->getId(), $favoriteIds, true),
            'last_played_at'      => $s->getLastPlayedAt()?->format(\DateTimeInterface::ATOM),
            'last_checked_at'     => $s->getLastCheckedAt()?->format(\DateTimeInterface::ATOM),
            'metadata'            => $s->getMetadata(),
            'customer_id'         => $s->getCustomer()?->getId(),
            'created_at'          => $s->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at'          => $s->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function assertWebradioAllowed(User $customer): void
    {
        $this->quotaService->assertWebradioAllowed($customer);
    }
}
