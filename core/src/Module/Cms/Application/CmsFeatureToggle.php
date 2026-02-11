<?php

declare(strict_types=1);

namespace App\Module\Cms\Application;

use App\Module\Core\Domain\Entity\Site;

final class CmsFeatureToggle
{
    /**
     * @var array<string, bool>
     */
    private const DEFAULTS = [
        'blog' => true,
        'events' => true,
        'team' => true,
        'forum' => true,
        'media' => true,
        'gameserver' => true,
    ];

    public function __construct(private readonly CmsSettingsProvider $settingsProvider)
    {
    }

    public function isEnabled(Site $site, string $module): bool
    {
        $toggles = $this->forSite($site);

        return $toggles[$module] ?? true;
    }

    /**
     * @return array<string, bool>
     */
    public function forSite(Site $site): array
    {
        $stored = $this->settingsProvider->getModuleToggles($site);
        $resolved = self::DEFAULTS;

        foreach (self::DEFAULTS as $key => $_default) {
            if (array_key_exists($key, $stored)) {
                $resolved[$key] = (bool) $stored[$key];
            }
        }

        return $resolved;
    }
}
