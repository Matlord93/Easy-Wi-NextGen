<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Public;

use App\Module\Cms\Application\PageResolver;
use App\Module\Core\Application\SiteResolver;
use App\Repository\CmsEventRepository;
use App\Repository\CmsPostRepository;
use App\Repository\ForumBoardRepository;
use App\Repository\TeamMemberRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/_debug/page-source', env: 'dev')]
final class DebugPageSourceController
{
    public function __construct(
        private readonly SiteResolver $siteResolver,
        private readonly PageResolver $pageResolver,
        private readonly CmsPostRepository $postRepository,
        private readonly CmsEventRepository $eventRepository,
        private readonly TeamMemberRepository $teamRepository,
        private readonly ForumBoardRepository $forumBoardRepository,
    ) {
    }

    #[Route('/{slug}', name: 'debug_page_source', methods: ['GET'])]
    public function __invoke(Request $request, string $slug): Response
    {
        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new JsonResponse(['slug' => $slug, 'source' => 'fallback', 'reason' => 'site-not-found'], 404);
        }

        if ($this->pageResolver->resolvePublishedPage($site, $slug) !== null) {
            return new JsonResponse(['slug' => $slug, 'source' => 'new-cms']);
        }

        $legacy = $this->postRepository->findOneBy(['site' => $site, 'slug' => $slug])
            ?? $this->eventRepository->findOneBy(['site' => $site, 'slug' => $slug])
            ?? $this->forumBoardRepository->findOneBy(['site' => $site, 'slug' => $slug]);

        if ($legacy !== null || ($slug === 'teams' && $this->teamRepository->count(['site' => $site]) > 0)) {
            return new JsonResponse(['slug' => $slug, 'source' => 'legacy-cms']);
        }

        return new JsonResponse(['slug' => $slug, 'source' => 'fallback']);
    }
}
