<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotRadioFavorite;
use App\Module\Musicbot\Domain\Entity\MusicbotRadioStation;

/** @extends \Doctrine\Persistence\ObjectRepository<MusicbotRadioFavorite> */

interface MusicbotRadioFavoriteRepositoryInterface extends \Doctrine\Persistence\ObjectRepository
{
    /** @return MusicbotRadioFavorite[] */
    public function findByCustomer(User $customer): array;

    public function findOneByCustomerAndStation(User $customer, MusicbotRadioStation $station): ?MusicbotRadioFavorite;

    /** @return int[] Station IDs that this customer has favorited. */
    public function getFavoriteStationIds(User $customer): array;
}
