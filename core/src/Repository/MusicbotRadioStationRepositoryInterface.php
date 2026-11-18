<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotRadioStation;

/** @extends \Doctrine\Persistence\ObjectRepository<MusicbotRadioStation> */

interface MusicbotRadioStationRepositoryInterface extends \Doctrine\Persistence\ObjectRepository
{
    /** @return MusicbotRadioStation[] */
    public function findByCustomer(User $customer): array;

    public function findOneForCustomer(int $id, User $customer): ?MusicbotRadioStation;

    public function countByCustomer(User $customer): int;

    /**
     * @param array<array-key, mixed> $filters
     * @return list<MusicbotRadioStation>
     */
    public function searchCatalog(array $filters = []): array;

    /** @param array<array-key, mixed> $filters */
    public function countCatalog(array $filters = []): int;

    /** @return MusicbotRadioStation[] */
    public function findPopularCatalogStations(array $popularIds, int $limit = 20): array;

    /** @return MusicbotRadioStation[] */
    public function findNewestCatalogStations(int $limit = 20): array;

    /** @return string[] */
    public function getDistinctGenres(): array;

    /** @return string[] */
    public function getDistinctCountries(): array;

    /** @return MusicbotRadioStation[] All customer-submitted (private) stations awaiting promotion. */
    public function findPendingCustomerStations(int $limit = 100): array;

    /** @return MusicbotRadioStation[] */
    public function findAllGlobal(bool $includeInactive = true): array;
}
