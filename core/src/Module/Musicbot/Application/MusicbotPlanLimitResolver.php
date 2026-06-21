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
        'max_plugins' => 5,
        'max_queue_items' => 50,
        'max_connections' => 2,
        'max_upload_size_mb' => 100,
        'allow_teamspeak' => true,
        'allow_discord' => false,
        'allow_teamspeak6_profile' => false,
        'allow_webradio' => false,
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
            maxMusicbots: $override?->getMaxMusicbots() ?? self::DEFAULTS['max_musicbots'],
            maxTracks: $override?->getMaxTracks() ?? self::DEFAULTS['max_tracks'],
            maxStorageMb: $override?->getMaxStorageMb() ?? self::DEFAULTS['max_storage_mb'],
            maxPlaylists: $override?->getMaxPlaylists() ?? self::DEFAULTS['max_playlists'],
            maxPlugins: $override?->getMaxPlugins() ?? self::DEFAULTS['max_plugins'],
            maxQueueItems: $override?->getMaxQueueItems() ?? self::DEFAULTS['max_queue_items'],
            maxConnections: $override?->getMaxConnections() ?? self::DEFAULTS['max_connections'],
            maxUploadSizeMb: $override?->getMaxUploadSizeMb() ?? self::DEFAULTS['max_upload_size_mb'],
            allowTeamspeak: $override?->getAllowTeamspeak() ?? self::DEFAULTS['allow_teamspeak'],
            allowDiscord: $override?->getAllowDiscord() ?? self::DEFAULTS['allow_discord'],
            allowTeamspeak6Profile: $override?->getAllowTeamspeak6Profile() ?? self::DEFAULTS['allow_teamspeak6_profile'],
            allowWebradio: $override?->getAllowWebradio() ?? self::DEFAULTS['allow_webradio'],
            allowPlugins: $override?->getAllowPlugins() ?? self::DEFAULTS['allow_plugins'],
            allowWorkflows: $override?->getAllowWorkflows() ?? self::DEFAULTS['allow_workflows'],
            allowScheduler: $override?->getAllowScheduler() ?? self::DEFAULTS['allow_scheduler'],
            grantedPermissions: $override?->getGrantedPermissions() ?? $defaultPermissions,
        );
    }

    /** @return array<string, mixed> */
    public static function planDefaults(): array
    {
        return self::DEFAULTS;
    }
}
