<?php

declare(strict_types=1);

namespace App\Module\Cms\Application;

use App\Module\Core\Domain\Entity\CmsSiteSettings;
use App\Module\Core\Domain\Entity\Site;
use App\Repository\CmsSiteSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

final class CmsSettingsProvider
{
    /** @var array<string, bool> */
    public const DEFAULT_MODULE_TOGGLES = [
        'blog' => true,
        'events' => true,
        'team' => true,
        'forum' => true,
        'media' => true,
        'gameserver' => true,
        'impressum' => true,
        'datenschutz' => true,
    ];

    /** @var array<string, string> */
    public const SOCIAL_KEYS = [
        'website' => 'website',
        'discord' => 'discord',
        'twitter' => 'twitter',
        'youtube' => 'youtube',
    ];

    public function __construct(
        private readonly CmsSiteSettingsRepository $settingsRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getOrCreate(Site $site): CmsSiteSettings
    {
        $settings = $this->findForSite($site);
        if ($settings instanceof CmsSiteSettings) {
            return $settings;
        }

        $settings = new CmsSiteSettings($site);
        $this->entityManager->persist($settings);

        return $settings;
    }

    public function findForSite(Site $site): ?CmsSiteSettings
    {
        return $this->settingsRepository->findOneBySite($site);
    }

    /** @return array{logo_path: string, primary_color: string, socials: array<string, string>} */
    public function getBranding(Site $site): array
    {
        $settings = $this->findForSite($site);
        $branding = is_array($settings?->getBrandingJson()) ? $settings->getBrandingJson() : [];

        $socials = [];
        $rawSocials = $branding['socials'] ?? [];
        foreach (self::SOCIAL_KEYS as $key) {
            $value = is_array($rawSocials) ? ($rawSocials[$key] ?? '') : '';
            $socials[$key] = is_string($value) ? trim($value) : '';
        }

        return [
            'logo_path' => is_string($branding['logoPath'] ?? null) ? trim((string) $branding['logoPath']) : '',
            'primary_color' => is_string($branding['primaryColor'] ?? null) ? trim((string) $branding['primaryColor']) : '',
            'socials' => $socials,
        ];
    }

    /** @return list<array{label: string, url: string, external: bool}> */
    public function getNavigationLinks(Site $site): array
    {
        $default = [
            ['label' => 'Start', 'url' => '/', 'external' => false],
            ['label' => 'Über uns', 'url' => '/ueber-uns', 'external' => false],
            ['label' => 'Teams', 'url' => '/teams', 'external' => false],
            ['label' => 'Events', 'url' => '/events', 'external' => false],
            ['label' => 'Blog', 'url' => '/blog', 'external' => false],
            ['label' => 'Forum', 'url' => '/forum', 'external' => false],
            ['label' => 'Kontakt', 'url' => '/kontakt', 'external' => false],
            ['label' => 'Discord', 'url' => 'https://discord.com', 'external' => true],
        ];

        return $this->normalizeLinks($this->findForSite($site)?->getHeaderLinksJson(), $default);
    }

    /** @return list<array{label: string, url: string, external: bool}> */
    public function getFooterLinks(Site $site): array
    {
        $default = [
            ['label' => 'Impressum', 'url' => '/impressum', 'external' => false],
            ['label' => 'Datenschutz', 'url' => '/datenschutz', 'external' => false],
            ['label' => 'AGB', 'url' => '/agb', 'external' => false],
            ['label' => 'Cookie-Richtlinie', 'url' => '/cookies', 'external' => false],
        ];

        $links = $this->normalizeLinks($this->findForSite($site)?->getFooterLinksJson(), $default);
        $moduleToggles = $this->getModuleToggles($site);

        return array_values(array_filter($links, static function (array $link) use ($moduleToggles): bool {
            return match ($link['url']) {
                '/impressum' => $moduleToggles['impressum'] ?? true,
                '/datenschutz' => $moduleToggles['datenschutz'] ?? true,
                default => true,
            };
        }));
    }

    /** @return array<string, bool> */
    public function getModuleToggles(Site $site): array
    {
        $settings = $this->findForSite($site);
        $stored = is_array($settings?->getModuleTogglesJson()) ? $settings->getModuleTogglesJson() : [];
        $resolved = self::DEFAULT_MODULE_TOGGLES;

        foreach (self::DEFAULT_MODULE_TOGGLES as $key => $_default) {
            if (array_key_exists($key, $stored)) {
                $resolved[$key] = (bool) $stored[$key];
            }
        }

        return $resolved;
    }

    /**
     * @param array{logo_path?: string, primary_color?: string, socials?: array<string, string>} $branding
     * @param array<string, bool> $moduleToggles
     * @param list<array{label?: string, url?: string, external?: bool|string|int}> $headerLinks
     * @param list<array{label?: string, url?: string, external?: bool|string|int}> $footerLinks
     */
    public function save(Site $site, ?string $activeTheme, array $branding, array $moduleToggles, array $headerLinks = [], array $footerLinks = []): CmsSiteSettings
    {
        $settings = $this->getOrCreate($site);
        $settings->setActiveTheme($activeTheme);
        $settings->setBrandingJson($this->normalizeBranding($branding));
        $settings->setModuleTogglesJson($this->normalizeModuleToggles($moduleToggles));
        $settings->setHeaderLinksJson($this->normalizeLinks($headerLinks, []));
        $settings->setFooterLinksJson($this->normalizeLinks($footerLinks, []));

        $this->entityManager->persist($settings);
        $this->entityManager->flush();

        return $settings;
    }

    /** @param mixed $links @return list<array{label: string, url: string, external: bool}> */
    private function normalizeLinks(mixed $links, array $fallback): array
    {
        if (!is_array($links) || $links === []) {
            return $fallback;
        }

        $normalized = [];
        foreach ($links as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $label = trim((string) ($entry['label'] ?? ''));
            $url = trim((string) ($entry['url'] ?? ''));
            if ($label === '' || $url === '') {
                continue;
            }

            $external = filter_var($entry['external'] ?? false, FILTER_VALIDATE_BOOL);
            $normalized[] = ['label' => $label, 'url' => $url, 'external' => $external];
        }

        return $normalized === [] ? $fallback : $normalized;
    }

    /**
     * @param array{logo_path?: string, primary_color?: string, socials?: array<string, string>} $branding
     * @return array{logoPath: ?string, primaryColor: ?string, socials: array<string, string>}|null
     */
    private function normalizeBranding(array $branding): ?array
    {
        $logoPath = trim((string) ($branding['logo_path'] ?? ''));
        $primaryColor = trim((string) ($branding['primary_color'] ?? ''));

        $socials = [];
        $rawSocials = is_array($branding['socials'] ?? null) ? $branding['socials'] : [];
        foreach (self::SOCIAL_KEYS as $key) {
            $value = trim((string) ($rawSocials[$key] ?? ''));
            if ($value !== '') {
                $socials[$key] = $value;
            }
        }

        if ($logoPath === '' && $primaryColor === '' && $socials === []) {
            return null;
        }

        return [
            'logoPath' => $logoPath !== '' ? $logoPath : null,
            'primaryColor' => $primaryColor !== '' ? $primaryColor : null,
            'socials' => $socials,
        ];
    }

    /** @param array<string, bool> $moduleToggles @return array<string, bool> */
    private function normalizeModuleToggles(array $moduleToggles): array
    {
        $normalized = self::DEFAULT_MODULE_TOGGLES;

        foreach (self::DEFAULT_MODULE_TOGGLES as $key => $_default) {
            if (array_key_exists($key, $moduleToggles)) {
                $normalized[$key] = (bool) $moduleToggles[$key];
            }
        }

        return $normalized;
    }
}
