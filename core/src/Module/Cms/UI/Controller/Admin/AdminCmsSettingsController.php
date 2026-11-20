<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Admin;

use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Http\RequestActor;
use App\Module\Cms\Application\CmsSettingsProvider;
use App\Module\Cms\Application\CustomWebsiteDirectory;
use App\Module\Core\Application\ModuleRegistry;
use App\Module\Cms\Application\CmsSiteResolver;
use App\Module\Core\Domain\Entity\CmsPage;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[Route(path: '/admin/cms/settings')]
final class AdminCmsSettingsController
{
    public function __construct(
        private readonly CmsSiteResolver $siteResolver,
        private readonly CmsSettingsProvider $settingsProvider,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly ModuleRegistry $modules,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly CustomWebsiteDirectory $website,
    ) {
    }

    #[Route(path: '', name: 'admin_cms_settings', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $actor = RequestActor::user($request);
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if (null === $site) {
            return new Response($this->translator->trans('error_site_not_found'), Response::HTTP_NOT_FOUND);
        }

        $settings = $this->settingsProvider->findForSite($site);

        return new Response($this->twig->render('admin/cms/settings/index.html.twig', [
            'activeNav' => 'cms-settings',
            'saved' => '1' === $request->query->get('saved'),
            'cms_enabled' => $this->modules->isEnabled('cms'),
            'custom_website_path' => $this->website->path(),
            'current_theme' => $settings?->getActiveTheme() ?? '',
            'branding' => $this->settingsProvider->getBranding($site),
            'module_toggles' => $this->settingsProvider->getModuleToggles($site),
            'header_links_json' => json_encode($this->settingsProvider->getNavigationLinks($site), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'footer_links_json' => json_encode($this->settingsProvider->getFooterLinks($site), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'impressum_content' => $this->settingsProvider->getImpressumContent($site),
            'datenschutz_content' => $this->settingsProvider->getDatenschutzContent($site),
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
        $actor = RequestActor::user($request);
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        if (!$this->csrf->isTokenValid(new CsrfToken('cms-settings', (string) $request->request->get('_token', '')))) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if (null === $site) {
            return new Response($this->translator->trans('error_site_not_found'), Response::HTTP_NOT_FOUND);
        }

        $theme = trim((string) $request->request->get('active_theme', ''));
        $theme = '' === $theme ? null : $theme;

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
            $toggles[$key] = '1' === $request->request->get(sprintf('toggle_%s', $key));
        }

        $headerLinks = $this->decodeLinks((string) $request->request->get('header_links_json', '[]'));
        $footerLinks = $this->decodeLinks((string) $request->request->get('footer_links_json', '[]'));
        $impressumContent = (string) $request->request->get('impressum_content', '');
        $datenschutzContent = (string) $request->request->get('datenschutz_content', '');

        $site->setMaintenanceEnabled('1' === $request->request->get('maintenance_enabled'));
        $site->setMaintenanceMessage((string) $request->request->get('maintenance_message', ''));
        $site->setMaintenanceGraphicPath((string) $request->request->get('maintenance_graphic_path', ''));
        $site->setMaintenanceAllowlist((string) $request->request->get('maintenance_allowlist', ''));
        $site->setMaintenanceStartsAt($this->parseDateTime((string) $request->request->get('maintenance_starts_at', '')));
        $site->setMaintenanceEndsAt($this->parseDateTime((string) $request->request->get('maintenance_ends_at', '')));

        $this->settingsProvider->save($site, $theme, $branding, $toggles, $headerLinks, $footerLinks, $impressumContent, $datenschutzContent);
        $this->ensureRequiredPagesExist($site);
        $this->entityManager->flush();

        return new RedirectResponse('/admin/cms/settings?saved=1');
    }

    #[Route(path: '/mode', name: 'admin_cms_mode_save', methods: ['POST'])]
    public function mode(Request $request): Response
    {
        $actor = RequestActor::user($request);
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }
        if (!$this->csrf->isTokenValid(new CsrfToken('cms-mode', $request->request->getString('_token')))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }
        $enabled = $request->request->getBoolean('enabled');
        if (!$enabled) {
            $this->website->prepare();
        }
        $this->modules->setEnabled('cms', $enabled, $actor);
        $this->entityManager->flush();

        return new RedirectResponse('/admin/cms/settings?saved=1', Response::HTTP_SEE_OTHER);
    }

    private function ensureRequiredPagesExist(Site $site): void
    {
        $requiredPages = [
            'startseite' => 'Startseite',
            'ueber-uns' => 'Über uns',
            'agb' => 'AGB',
            'impressum' => 'Impressum',
            'datenschutz' => 'Datenschutz',
            'kontakt' => 'Kontakt',
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
        if ('' === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<array-key, mixed> */
    private function decodeLinks(string $json): array
    {
        return JsonArray::decode($json) ?? [];
    }
}
