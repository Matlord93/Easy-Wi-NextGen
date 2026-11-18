<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Musicbot\Domain\Entity\MusicbotConnection;

/** @extends \Doctrine\Persistence\ObjectRepository<MusicbotConnection> */

interface MusicbotConnectionRepositoryInterface extends \Doctrine\Persistence\ObjectRepository
{
}
