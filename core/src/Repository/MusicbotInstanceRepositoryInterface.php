<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;

interface MusicbotInstanceRepositoryInterface
{
    public function findById(int $id): ?MusicbotInstance;

    /** @return MusicbotInstance[] */
    public function findByCustomer(User $customer): array;

    public function findOneForCustomer(int $id, User $customer): ?MusicbotInstance;
}
