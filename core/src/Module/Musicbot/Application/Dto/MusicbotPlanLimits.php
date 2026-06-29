<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application\Dto;

final readonly class MusicbotPlanLimits
{
    /**
     * @param string[] $grantedPermissions
     */
    public function __construct(
        public int $maxMusicbots,
        public int $maxTracks,
        public int $maxStorageMb,
        public int $maxPlaylists,
        public int $maxPlaylistItems,
        public int $maxPlugins,
        public int $maxQueueItems,
        public int $maxConnections,
        public int $maxUploadSizeMb,
        public bool $allowTeamspeak,
        public bool $allowDiscord,
        public bool $allowStream,
        public bool $allowApi,
        public bool $allowTeamspeak6Profile,
        public bool $allowWebradio,
        public bool $allowYoutube,
        public bool $allowTeamspeakCommands,
        public bool $allowPlaylists,
        public bool $allowAutoDj,
        public bool $allowPlugins,
        public bool $allowWorkflows,
        public bool $allowScheduler,
        public array $grantedPermissions,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'max_musicbots' => $this->maxMusicbots,
            'max_tracks' => $this->maxTracks,
            'max_storage_mb' => $this->maxStorageMb,
            'max_playlists' => $this->maxPlaylists,
            'max_playlist_items' => $this->maxPlaylistItems,
            'max_plugins' => $this->maxPlugins,
            'max_queue_items' => $this->maxQueueItems,
            'max_connections' => $this->maxConnections,
            'max_upload_size_mb' => $this->maxUploadSizeMb,
            'allow_teamspeak' => $this->allowTeamspeak,
            'allow_discord' => $this->allowDiscord,
            'discord_allowed' => $this->allowDiscord,
            'stream_allowed' => $this->allowStream,
            'api_allowed' => $this->allowApi,
            'allow_teamspeak6_profile' => $this->allowTeamspeak6Profile,
            'allow_webradio' => $this->allowWebradio,
            'web_radio_allowed' => $this->allowWebradio,
            'youtube_allowed' => $this->allowYoutube,
            'teamspeak_commands_allowed' => $this->allowTeamspeakCommands,
            'playlists_allowed' => $this->allowPlaylists,
            'autodj_allowed' => $this->allowAutoDj,
            'allow_plugins' => $this->allowPlugins,
            'plugins_allowed' => $this->allowPlugins,
            'allow_workflows' => $this->allowWorkflows,
            'allow_scheduler' => $this->allowScheduler,
            'granted_permissions' => $this->grantedPermissions,
        ];
    }
}
