<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotRadioHistory;

/** @extends \Doctrine\Persistence\ObjectRepository<MusicbotRadioHistory> */

interface MusicbotRadioHistoryRepositoryInterface extends \Doctrine\Persistence\ObjectRepository
{
    /** @return MusicbotRadioHistory[] */
    public function findByCustomer(User $customer, int $limit = 20): array;

    /** Prune history entries older than $keepDays days for the customer. */
    public function pruneOld(User $customer, int $keepRows = 100): void;

    /** @return array<int,int> stationId => playCount, sorted desc */
    public function getPopularStationIds(int $limit = 20): array;
}
