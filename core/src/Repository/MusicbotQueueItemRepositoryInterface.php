<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotQueueItem;

interface MusicbotQueueItemRepositoryInterface
{
    /** @return MusicbotQueueItem[] */
    public function findQueueForInstanceOrdered(MusicbotInstance $instance): array;
}
