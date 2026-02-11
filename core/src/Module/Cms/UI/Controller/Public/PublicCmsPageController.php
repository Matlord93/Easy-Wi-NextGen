<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Public;

use App\Module\Cms\Application\BlockRenderer\BlockRendererRegistry;
use App\Module\Cms\Application\CmsMaintenanceService;
use App\Module\Cms\Application\CmsSettingsProvider;
use App\Module\Cms\Application\PageResolver;
use App\Module\Cms\Application\ThemeResolver;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\CmsBlock;
use App\Module\Setup\Application\InstallerService;
use App\Repository\CmsBlockRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class PublicCmsPageController
{
    public function __construct(
        private readonly PageResolver $pageResolver,
        private readonly CmsBlockRepository $blockRepository,
        private readonly SiteResolver $siteResolver,
        private readonly InstallerService $installerService,
        private readonly CmsMaintenanceService $maintenanceService,
        private readonly ThemeResolver $themeResolver,
        private readonly CmsSettingsProvider $settingsProvider,
        private readonly BlockRendererRegistry $blockRendererRegistry,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '/', name: 'public_cms_home', methods: ['GET'], priority: 20)]
    public function home(Request $request): Response
    {
        return $this->renderPage($request, 'startseite');
    }

    #[Route(
        path: '/{slug}',
        name: 'public_cms_page_slug',
        methods: ['GET'],
        requirements: ['slug' => '(?!login$|logout$|register$|2fa$|2fa_check$|admin$|admin/|api$|api/|system$|system/|assets$|assets/|_profiler$|_wdt$|install$)[a-z0-9][a-z0-9-]*'],
        priority: -100
    )]
    public function show(Request $request, string $slug): Response
    {
        return $this->renderPage($request, $slug);
    }

    private function renderPage(Request $request, string $slug): Response
    {
        if (!$this->installerService->isLocked()) {
            return new RedirectResponse('/install');
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        if ($this->pageResolver->isReservedSlug($slug)) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $maintenance = $this->maintenanceService->resolve($request, $site);
        if ($maintenance['active']) {
            return new Response($this->twig->render('public/maintenance.html.twig', [
                'message' => $maintenance['message'],
                'graphic_path' => $maintenance['graphic_path'],
                'starts_at' => $maintenance['starts_at'],
                'ends_at' => $maintenance['ends_at'],
                'scope' => $maintenance['scope'],
            ]), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $page = $this->pageResolver->resolvePublishedPage($site, $slug);
        if ($page === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $blocks = $this->blockRepository->findBy(['page' => $page], ['sortOrder' => 'ASC']);
        $templateKey = $this->themeResolver->resolveThemeKey($site);

        return new Response($this->twig->render($this->resolveTemplate($templateKey, $slug), [
            'page' => [
                'title' => $page->getTitle(),
                'slug' => $page->getSlug(),
            ],
            'blocks' => $this->normalizeBlocks($blocks),
            'template_key' => $templateKey,
            'active_theme' => $templateKey,
            'cms_navigation' => $this->settingsProvider->getNavigationLinks($site),
            'cms_footer_links' => $this->settingsProvider->getFooterLinks($site),
            'cms_branding' => $this->settingsProvider->getBranding($site),
        ]));
    }

    /** @param CmsBlock[] $blocks */
    private function normalizeBlocks(array $blocks): array
    {
        return array_map(fn (CmsBlock $block): array => [
            'type' => $block->getType(),
            'html' => $this->blockRendererRegistry->render($block),
            'servers' => [],
            'settings' => ['show_players' => false, 'show_join_button' => false],
        ], $blocks);
    }

    private function resolveTemplate(string $templateKey, string $slug): string
    {
        $themeSlugTemplate = sprintf('themes/%s/pages/%s.html.twig', $templateKey, $slug);
        if ($this->templateExists($themeSlugTemplate)) {
            return $themeSlugTemplate;
        }

        $themeDefaultTemplate = sprintf('themes/%s/pages/default.html.twig', $templateKey);
        if ($this->templateExists($themeDefaultTemplate)) {
            return $themeDefaultTemplate;
        }

        return 'themes/minimal/pages/default.html.twig';
    }

    private function templateExists(string $template): bool
    {
        $loader = $this->twig->getLoader();

        return method_exists($loader, 'exists') && $loader->exists($template);
    }
}
