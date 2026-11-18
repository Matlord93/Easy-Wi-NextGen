<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;

interface MusicbotQuotaServiceInterface
{
    public function assertCanCreateMusicbot(User $customer): void;

    public function assertCanUploadTrack(User $customer, int $fileSizeBytes): void;

    public function assertCanCreatePlaylist(User $customer): void;

    public function assertCanAddPlaylistItem(User $customer, \App\Module\Musicbot\Domain\Entity\MusicbotPlaylist $playlist): void;

    public function assertCanAddToQueue(User $customer, MusicbotInstance $instance): void;

    public function assertCanAssignPlugin(User $customer): void;

    public function assertCanManageTeamspeakConnection(User $customer): void;

    public function assertTeamspeakCommandsAllowed(User $customer): void;

    public function assertCanUseTeamspeak6Profile(User $customer): void;

    public function assertCanManageDiscordConnection(User $customer): void;

    public function assertWebradioAllowed(User $customer): void;

    public function assertYoutubeAllowed(User $customer): void;

    public function assertAutoDjAllowed(User $customer): void;

    public function assertStreamAllowed(User $customer): void;

    public function assertApiAllowed(User $customer): void;

    public function assertWorkflowsAllowed(User $customer): void;

    public function assertSchedulerAllowed(User $customer): void;

    /** @return array{
     * musicbots: array{used: int, max: int}, tracks: array{used: int, max: int},
     * storage_mb: array{used: float, max: int}, playlists: array{used: int, max: int},
     * playlist_items: array{used: int, max: int}, plugins: array{used: int, max: int},
     * limits: array<string, mixed>
     * } */
    public function usageForCustomer(User $customer): array;
}
