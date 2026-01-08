<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CmsBlock;
use App\Entity\PublicServer;
use App\Repository\CmsBlockRepository;
use App\Repository\CmsPageRepository;
use App\Repository\PublicServerRepository;
use App\Service\SiteResolver;
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
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '/pages/{slug}', name: 'public_cms_page', methods: ['GET'])]
    public function show(Request $request, string $slug): Response
    {
        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $page = $this->pageRepository->findOneBy(['slug' => $slug, 'isPublished' => true, 'site' => $site]);
        if ($page === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $blocks = $this->blockRepository->findBy(['page' => $page], ['sortOrder' => 'ASC']);

        return new Response($this->twig->render('public/pages/show.html.twig', [
            'page' => [
                'title' => $page->getTitle(),
                'slug' => $page->getSlug(),
            ],
            'blocks' => $this->normalizeBlocks($blocks, $site->getId() ?? 0),
        ]));
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
}
