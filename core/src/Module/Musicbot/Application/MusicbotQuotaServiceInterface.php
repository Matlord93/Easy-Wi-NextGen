<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;

interface MusicbotQuotaServiceInterface
{
    public function assertCanCreateMusicbot(User $customer): void;

    public function assertCanAddToQueue(User $customer, MusicbotInstance $instance): void;

    public function assertCanUploadTrack(User $customer, int $fileSizeBytes): void;

    public function assertWebradioAllowed(User $customer): void;

    public function assertCanManageTeamspeakConnection(User $customer): void;
}
