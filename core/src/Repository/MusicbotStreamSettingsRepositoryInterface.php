<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotStreamSettings;

/** @extends \Doctrine\Persistence\ObjectRepository<MusicbotStreamSettings> */

interface MusicbotStreamSettingsRepositoryInterface extends \Doctrine\Persistence\ObjectRepository
{
    public function findByInstance(MusicbotInstance $instance): ?MusicbotStreamSettings;
}
