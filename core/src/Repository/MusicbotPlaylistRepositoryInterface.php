<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotPlaylist;

/** @extends \Doctrine\Persistence\ObjectRepository<MusicbotPlaylist> */

interface MusicbotPlaylistRepositoryInterface extends \Doctrine\Persistence\ObjectRepository
{
    /** @return MusicbotPlaylist[] */
    public function findByCustomer(User $customer): array;

    public function findOneForCustomer(int $id, User $customer): ?MusicbotPlaylist;
}
