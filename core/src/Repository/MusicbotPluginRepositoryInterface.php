<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotPlugin;

/** @extends \Doctrine\Persistence\ObjectRepository<MusicbotPlugin> */

interface MusicbotPluginRepositoryInterface extends \Doctrine\Persistence\ObjectRepository
{
    /** @return MusicbotPlugin[] */
    public function findByCustomer(User $customer): array;

    public function findOneForCustomer(int $id, User $customer): ?MusicbotPlugin;

    public function findOneByIdentifierForInstance(string $identifier, MusicbotInstance $instance, User $customer): ?MusicbotPlugin;
}
