<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotTrack;

interface MusicbotTrackRepositoryInterface
{
    public function findOneForCustomer(int $id, User $customer): ?MusicbotTrack;

    /** @return MusicbotTrack[] */
    public function findByCustomer(User $customer): array;
}
