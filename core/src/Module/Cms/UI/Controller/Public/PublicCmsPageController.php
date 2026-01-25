<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Public;

use App\Module\Core\Domain\Entity\CmsBlock;
use App\Module\Core\Domain\Entity\PublicServer;
use App\Repository\CmsBlockRepository;
use App\Repository\CmsPageRepository;
use App\Repository\PublicServerRepository;
use App\Module\Core\Application\SiteResolver;
use App\Module\Setup\Application\InstallerService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class PublicCmsPageController
{
    public function __construct(
        private readonly CmsPageRepository $pageRepository,
        private readonly CmsBlockRepository $blockRepository,
        private readonly PublicServerRepository $publicServerRepository,
        private readonly SiteResolver $siteResolver,
        private readonly InstallerService $installerService,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '/', name: 'public_cms_home', methods: ['GET'], priority: 10)]
    public function home(Request $request): Response
    {
        return $this->renderPage($request, 'startseite');
    }

    #[Route(path: '/pages/{slug}', name: 'public_cms_page', methods: ['GET'])]
    #[Route(
        path: '/{slug}',
        name: 'public_cms_page_slug',
        methods: ['GET'],
        requirements: [
            'slug' => '(?!admin|api|pages|docs|status|servers|downloads|register|login|install|changelog|notifications|files|dashboard|instances|databases|tickets|activity|profile|gdpr|agent|reseller)([a-z0-9-]+)',
        ],
        priority: -10
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

        $page = $this->pageRepository->findOneBy(['slug' => $slug, 'isPublished' => true, 'site' => $site]);
        if ($page === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $blocks = $this->blockRepository->findBy(['page' => $page], ['sortOrder' => 'ASC']);
        $cmsPages = $this->pageRepository->findBy(
            ['isPublished' => true, 'site' => $site],
            ['title' => 'ASC']
        );
        $cmsPages = $this->normalizePages($cmsPages, $page->getSlug());

        $templateKey = $site->getCmsTemplateKey() ?? 'hosting';

        return new Response($this->twig->render($this->resolveTemplate($templateKey, $slug), [
            'page' => [
                'title' => $page->getTitle(),
                'slug' => $page->getSlug(),
            ],
            'blocks' => $this->normalizeBlocks($blocks, $site->getId() ?? 0),
            'cms_pages' => $cmsPages,
            'template_key' => $templateKey,
        ]));
    }

    /**
     * @param \App\Module\Core\Domain\Entity\CmsPage[] $pages
     */
    private function normalizePages(array $pages, string $currentSlug): array
    {
        $normalized = array_map(static fn ($page): array => [
            'title' => $page->getTitle(),
            'slug' => $page->getSlug(),
            'is_active' => $page->getSlug() === $currentSlug,
        ], $pages);

        usort($normalized, static function (array $left, array $right): int {
            if ($left['slug'] === 'startseite') {
                return -1;
            }

            if ($right['slug'] === 'startseite') {
                return 1;
            }

            return strcasecmp($left['title'], $right['title']);
        });

        return $normalized;
    }

    /**
     * @param CmsBlock[] $blocks
     */
    private function normalizeBlocks(array $blocks, int $siteId): array
    {
        return array_map(function (CmsBlock $block) use ($siteId): array {
            if (in_array($block->getType(), ['server_list', 'server_featured'], true)) {
                $settings = $this->decodeSettings($block->getContent());
                $servers = $this->publicServerRepository->findVisiblePublicBySite(
                    $siteId,
                    $settings['game'] ?? null,
                    null,
                    $settings['limit'] ?? null,
                );

                return [
                    'type' => $block->getType(),
                    'content' => null,
                    'servers' => $this->normalizeServers($servers),
                    'settings' => $settings,
                ];
            }

            return [
                'type' => $block->getType(),
                'content' => $block->getContent(),
                'servers' => [],
                'settings' => [
                    'show_players' => false,
                    'show_join_button' => false,
                ],
            ];
        }, $blocks);
    }

    private function decodeSettings(string $content): array
    {
        $settings = json_decode($content, true);
        if (!is_array($settings)) {
            $settings = [];
        }

        return [
            'game' => is_string($settings['game'] ?? null) ? $settings['game'] : null,
            'limit' => is_numeric($settings['limit'] ?? null) ? (int) $settings['limit'] : null,
            'show_players' => (bool) ($settings['show_players'] ?? true),
            'show_join_button' => (bool) ($settings['show_join_button'] ?? false),
        ];
    }

    /**
     * @param PublicServer[] $servers
     */
    private function normalizeServers(array $servers): array
    {
        return array_map(function (PublicServer $server): array {
            $statusCache = $server->getStatusCache();
            $statusValue = $statusCache['status'] ?? ($statusCache['online'] ?? null);

            return [
                'id' => $server->getId(),
                'name' => $server->getName(),
                'game_key' => $server->getGameKey(),
                'address' => sprintf('%s:%d', $server->getIp(), $server->getPort()),
                'status' => $this->normalizeStatus($statusValue),
                'players' => is_numeric($statusCache['players'] ?? null) ? (int) $statusCache['players'] : null,
                'max_players' => is_numeric($statusCache['max_players'] ?? null) ? (int) $statusCache['max_players'] : null,
                'map' => is_string($statusCache['map'] ?? null) ? $statusCache['map'] : null,
                'last_checked_at' => $server->getLastCheckedAt(),
            ];
        }, $servers);
    }

    private function normalizeStatus(mixed $statusValue): string
    {
        if (is_string($statusValue) && $statusValue !== '') {
            return strtolower($statusValue);
        }

        if ($statusValue === true) {
            return 'online';
        }

        if ($statusValue === false) {
            return 'offline';
        }

        return 'unknown';
    }

    private function resolveTemplate(string $templateKey, string $slug): string
    {
        $customSlugTemplate = sprintf('public/pages/custom/%s/%s.html.twig', $templateKey, $slug);
        if ($this->templateExists($customSlugTemplate)) {
            return $customSlugTemplate;
        }

        $customTemplate = sprintf('public/pages/custom/%s.html.twig', $templateKey);
        if ($this->templateExists($customTemplate)) {
            return $customTemplate;
        }

        return match ($templateKey) {
            'clan' => 'public/pages/show_clan.html.twig',
            'private' => 'public/pages/show_private.html.twig',
            default => 'public/pages/show_hosting.html.twig',
        };
    }

    private function templateExists(string $template): bool
    {
        $loader = $this->twig->getLoader();

        return method_exists($loader, 'exists') && $loader->exists($template);
    }
}
