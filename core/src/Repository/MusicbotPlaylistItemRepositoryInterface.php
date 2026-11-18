<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotPlaylist;
use App\Module\Musicbot\Domain\Entity\MusicbotPlaylistItem;

/** @extends \Doctrine\Persistence\ObjectRepository<MusicbotPlaylistItem> */

interface MusicbotPlaylistItemRepositoryInterface extends \Doctrine\Persistence\ObjectRepository
{
    /** @return MusicbotPlaylistItem[] */
    public function findByPlaylistOrdered(MusicbotPlaylist $playlist): array;

    public function findOneForCustomer(int $id, User $customer): ?MusicbotPlaylistItem;
}
