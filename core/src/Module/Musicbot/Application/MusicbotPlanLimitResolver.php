<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Application\Dto\MusicbotPlanLimits;
use App\Module\Musicbot\Domain\Enum\MusicbotPermission;
use App\Repository\MusicbotCustomerLimitsRepository;

final class MusicbotPlanLimitResolver
{
    private const DEFAULTS = [
        'max_musicbots' => 1,
        'max_tracks' => 100,
        'max_storage_mb' => 500,
        'max_playlists' => 10,
        'max_playlist_items' => 200,
        'max_plugins' => 5,
        'max_queue_items' => 50,
        'max_connections' => 2,
        'max_upload_size_mb' => 100,
        'allow_teamspeak' => true,
        'allow_discord' => false,
        'allow_stream' => true,
        'allow_api' => true,
        'allow_teamspeak6_profile' => false,
        'allow_webradio' => true,
        'allow_youtube' => true,
        'allow_teamspeak_commands' => true,
        'allow_playlists' => true,
        'allow_autodj' => true,
        'allow_plugins' => true,
        'allow_workflows' => false,
        'allow_scheduler' => false,
    ];

    public function __construct(
        private readonly MusicbotCustomerLimitsRepository $limitsRepository,
    ) {
    }

    public function resolve(User $customer): MusicbotPlanLimits
    {
        $override = $this->limitsRepository->findByCustomer($customer);
        $defaultPermissions = array_map(
            static fn (MusicbotPermission $p): string => $p->value,
            MusicbotPermission::customerDefaults(),
        );

        return new MusicbotPlanLimits(
            maxMusicbots: self::resolveLimit($override?->getMaxMusicbots(), self::DEFAULTS['max_musicbots']),
            maxTracks: self::resolveLimit($override?->getMaxTracks(), self::DEFAULTS['max_tracks']),
            maxStorageMb: self::resolveLimit($override?->getMaxStorageMb(), self::DEFAULTS['max_storage_mb']),
            maxPlaylists: self::resolveLimit($override?->getMaxPlaylists(), self::DEFAULTS['max_playlists']),
            maxPlaylistItems: self::resolveLimit($override?->getMaxPlaylistItems(), self::DEFAULTS['max_playlist_items']),
            maxPlugins: self::resolveLimit($override?->getMaxPlugins(), self::DEFAULTS['max_plugins']),
            maxQueueItems: self::resolveLimit($override?->getMaxQueueItems(), self::DEFAULTS['max_queue_items']),
            maxConnections: self::resolveLimit($override?->getMaxConnections(), self::DEFAULTS['max_connections']),
            maxUploadSizeMb: self::resolveLimit($override?->getMaxUploadSizeMb(), self::DEFAULTS['max_upload_size_mb']),
            allowTeamspeak: $override?->getAllowTeamspeak() ?? self::DEFAULTS['allow_teamspeak'],
            allowDiscord: $override?->getAllowDiscord() ?? self::DEFAULTS['allow_discord'],
            allowStream: $override?->getAllowStream() ?? self::DEFAULTS['allow_stream'],
            allowApi: $override?->getAllowApi() ?? self::DEFAULTS['allow_api'],
            allowTeamspeak6Profile: $override?->getAllowTeamspeak6Profile() ?? self::DEFAULTS['allow_teamspeak6_profile'],
            allowWebradio: $override?->getAllowWebradio() ?? self::DEFAULTS['allow_webradio'],
            allowYoutube: $override?->getGrantedPermissions() !== null ? in_array(MusicbotPermission::YoutubeManage->value, $override->getGrantedPermissions(), true) : self::DEFAULTS['allow_youtube'],
            allowTeamspeakCommands: $override?->getGrantedPermissions() !== null ? in_array(MusicbotPermission::TeamspeakCommandsManage->value, $override->getGrantedPermissions(), true) : self::DEFAULTS['allow_teamspeak_commands'],
            allowPlaylists: self::resolveLimit($override?->getMaxPlaylists(), self::DEFAULTS['max_playlists']) > 0,
            allowAutoDj: $override?->getGrantedPermissions() !== null ? in_array(MusicbotPermission::AutoDjManage->value, $override->getGrantedPermissions(), true) : self::DEFAULTS['allow_autodj'],
            allowPlugins: $override?->getAllowPlugins() ?? self::DEFAULTS['allow_plugins'],
            allowWorkflows: $override?->getAllowWorkflows() ?? self::DEFAULTS['allow_workflows'],
            allowScheduler: $override?->getAllowScheduler() ?? self::DEFAULTS['allow_scheduler'],
            grantedPermissions: $override?->getGrantedPermissions() ?? $defaultPermissions,
        );
    }

    private static function resolveLimit(?int $override, int $default): int
    {
        if ($override === null) {
            return $default;
        }

        return $override < 0 ? PHP_INT_MAX : $override;
    }

    /** @return array<string, mixed> */
    public static function planDefaults(): array
    {
        return self::DEFAULTS;
    }
}
