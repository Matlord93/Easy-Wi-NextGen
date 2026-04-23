<?php

declare(strict_types=1);

namespace App\Module\Cms\Application;

use App\Module\Core\Domain\Entity\Site;

final class ThemeResolver
{
    private const DEFAULT_THEME = 'minimal';

    private const THEME_ALIASES = [
        'theme1' => 'esports',
        'theme2' => 'minimal',
        'theme3' => 'fantasy',
    ];

    private const SUPPORTED_THEMES = ['esports', 'minimal', 'fantasy', 'clan-nova', 'neon-arena', 'titan-squad'];

    public function __construct(private readonly CmsSettingsProvider $settingsProvider)
    {
    }

    public function resolveThemeKey(Site $site): string
    {
        $settings = $this->settingsProvider->findForSite($site);
        $settingsTheme = trim((string) ($settings?->getActiveTheme() ?? ''));
        if ($settingsTheme !== '') {
            return $this->normalizeThemeKey($settingsTheme);
        }
        return self::DEFAULT_THEME;
    }

    /**
     * @return list<string>
     */
    public function supportedThemes(): array
    {
        return self::SUPPORTED_THEMES;
    }

    private function normalizeThemeKey(string $theme): string
    {
        $normalized = strtolower(trim($theme));
        if ($normalized === '') {
            return self::DEFAULT_THEME;
        }

        $normalized = self::THEME_ALIASES[$normalized] ?? $normalized;

        return in_array($normalized, self::SUPPORTED_THEMES, true) ? $normalized : self::DEFAULT_THEME;
    }
}
