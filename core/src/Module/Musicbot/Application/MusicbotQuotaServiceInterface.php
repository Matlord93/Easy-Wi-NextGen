<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotPlaylist;

interface MusicbotQuotaServiceInterface
{
    public function assertCanCreateMusicbot(User $customer): void;

    public function assertCanAddToQueue(User $customer, MusicbotInstance $instance): void;

    public function assertCanCreatePlaylist(User $customer): void;

    public function assertCanAddPlaylistItem(User $customer, MusicbotPlaylist $playlist): void;

    public function assertCanUploadTrack(User $customer, int $fileSizeBytes): void;

    public function assertWebradioAllowed(User $customer): void;

    public function assertYoutubeAllowed(User $customer): void;

    public function assertAutoDjAllowed(User $customer): void;

    public function assertStreamAllowed(User $customer): void;

    public function assertApiAllowed(User $customer): void;

    public function assertCanManageTeamspeakConnection(User $customer): void;

    public function assertTeamspeakCommandsAllowed(User $customer): void;
}
