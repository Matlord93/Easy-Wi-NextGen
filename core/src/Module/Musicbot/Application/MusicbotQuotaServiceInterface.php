<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;

interface MusicbotQuotaServiceInterface
{
    public function assertCanAddToQueue(User $customer, MusicbotInstance $instance): void;
}
