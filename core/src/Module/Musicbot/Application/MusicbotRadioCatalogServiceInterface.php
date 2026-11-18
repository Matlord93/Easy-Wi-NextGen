<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotRadioStation;

interface MusicbotRadioCatalogServiceInterface
{
    /**
     * Search the global catalog with optional filters.
     *
     * @param array{query?: string, genre?: string, country?: string, language?: string, format?: string, min_bitrate?: int, max_bitrate?: int, active_only?: bool, limit?: int, offset?: int} $filters
     * @return array{stations: array<int, array<string, mixed>>, total: int, genres: string[], countries: string[]}
     */
    public function search(User $customer, array $filters = []): array;

    /** @return array<array-key, array<string, mixed>> */
    public function getPopular(User $customer, int $limit = 20): array;

    /** @return array<array-key, array<string, mixed>> */
    public function getNewest(User $customer, int $limit = 20): array;

    /** @return array<array-key, array<string, mixed>> */
    public function getFavorites(User $customer): array;

    public function addFavorite(User $customer, MusicbotRadioStation $station): void;

    public function removeFavorite(User $customer, MusicbotRadioStation $station): void;

    /** @return array<array-key, array<string, mixed>> */
    public function getHistory(User $customer, int $limit = 20): array;

    public function recordHistory(User $customer, MusicbotRadioStation $station, ?\App\Module\Musicbot\Domain\Entity\MusicbotInstance $instance = null): void;

    /**
     * Promote a customer-submitted private station to the global catalog.
     */
    public function promoteToGlobal(MusicbotRadioStation $station): void;

    public function markInactive(MusicbotRadioStation $station): void;

    public function markActive(MusicbotRadioStation $station): void;

    /** @return array<array-key, array<string, mixed>> */
    public function getPendingCustomerStations(): array;

    /** @return array<array-key, array<string, mixed>> */
    public function getAllGlobal(bool $includeInactive = true): array;

    /**
     * @param int[] $favoriteIds
     * @return array{id:int|null,name:string,stream_url:string,is_favorite:bool,last_played_at:string|null,...<string,mixed>}
     */
    public function normalize(MusicbotRadioStation $s, array $favoriteIds = []): array;
}
