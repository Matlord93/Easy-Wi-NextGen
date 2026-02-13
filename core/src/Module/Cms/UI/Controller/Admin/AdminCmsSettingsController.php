<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Admin;

use App\Module\Cms\Application\CmsSettingsProvider;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\CmsPage;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/cms/settings')]
final class AdminCmsSettingsController
{
    public function __construct(
        private readonly SiteResolver $siteResolver,
        private readonly CmsSettingsProvider $settingsProvider,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_cms_settings', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $settings = $this->settingsProvider->findForSite($site);

        return new Response($this->twig->render('admin/cms/settings/index.html.twig', [
            'activeNav' => 'cms-settings',
            'saved' => $request->query->get('saved') === '1',
            'current_theme' => $settings?->getActiveTheme() ?? '',
            'branding' => $this->settingsProvider->getBranding($site),
            'module_toggles' => $this->settingsProvider->getModuleToggles($site),
            'header_links_json' => json_encode($this->settingsProvider->getNavigationLinks($site), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'footer_links_json' => json_encode($this->settingsProvider->getFooterLinks($site), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'maintenance' => [
                'enabled' => $site->isMaintenanceEnabled(),
                'message' => $site->getMaintenanceMessage(),
                'graphic_path' => $site->getMaintenanceGraphicPath(),
                'allowlist' => $site->getMaintenanceAllowlist(),
                'starts_at' => $site->getMaintenanceStartsAt()?->format('Y-m-d\TH:i'),
                'ends_at' => $site->getMaintenanceEndsAt()?->format('Y-m-d\TH:i'),
            ],
        ]));
    }

    #[Route(path: '', name: 'admin_cms_settings_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $theme = trim((string) $request->request->get('active_theme', ''));
        $theme = $theme === '' ? null : $theme;

        $branding = [
            'logo_path' => trim((string) $request->request->get('logo_path', '')),
            'primary_color' => trim((string) $request->request->get('primary_color', '')),
            'socials' => [
                'website' => trim((string) $request->request->get('social_website', '')),
                'discord' => trim((string) $request->request->get('social_discord', '')),
                'twitter' => trim((string) $request->request->get('social_twitter', '')),
                'youtube' => trim((string) $request->request->get('social_youtube', '')),
            ],
        ];

        $toggles = [];
        foreach (CmsSettingsProvider::DEFAULT_MODULE_TOGGLES as $key => $_default) {
            $toggles[$key] = $request->request->get(sprintf('toggle_%s', $key)) === '1';
        }

        $headerLinks = $this->decodeLinks((string) $request->request->get('header_links_json', '[]'));
        $footerLinks = $this->decodeLinks((string) $request->request->get('footer_links_json', '[]'));

        $site->setMaintenanceEnabled($request->request->get('maintenance_enabled') === '1');
        $site->setMaintenanceMessage((string) $request->request->get('maintenance_message', ''));
        $site->setMaintenanceGraphicPath((string) $request->request->get('maintenance_graphic_path', ''));
        $site->setMaintenanceAllowlist((string) $request->request->get('maintenance_allowlist', ''));
        $site->setMaintenanceStartsAt($this->parseDateTime((string) $request->request->get('maintenance_starts_at', '')));
        $site->setMaintenanceEndsAt($this->parseDateTime((string) $request->request->get('maintenance_ends_at', '')));

        $this->settingsProvider->save($site, $theme, $branding, $toggles, $headerLinks, $footerLinks);
        $this->ensureRequiredPagesExist($site);
        $this->entityManager->flush();

        return new RedirectResponse('/admin/cms/settings?saved=1');
    }

    private function ensureRequiredPagesExist(Site $site): void
    {
        $requiredPages = [
            'startseite' => 'Startseite',
            'ueber-uns' => 'Über uns',
            'agb' => 'AGB',
        ];

        $pageRepository = $this->entityManager->getRepository(CmsPage::class);
        foreach ($requiredPages as $slug => $title) {
            $page = $pageRepository->findOneBy([
                'site' => $site,
                'slug' => $slug,
            ]);
            if ($page instanceof CmsPage) {
                if (!$page->isPublished()) {
                    $page->setPublished(true);
                }
                continue;
            }

            $this->entityManager->persist(new CmsPage($site, $title, $slug, true));
        }
    }

    private function parseDateTime(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<array{label?: string, url?: string, external?: bool|string|int}> */
    private function decodeLinks(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
