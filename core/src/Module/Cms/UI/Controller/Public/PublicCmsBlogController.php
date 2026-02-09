<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Public;

use App\Module\Cms\Application\CmsMaintenanceService;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\CmsPost;
use App\Module\Setup\Application\InstallerService;
use App\Repository\CmsPageRepository;
use App\Repository\CmsPostRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class PublicCmsBlogController
{
    public function __construct(
        private readonly CmsPostRepository $postRepository,
        private readonly CmsPageRepository $pageRepository,
        private readonly SiteResolver $siteResolver,
        private readonly InstallerService $installerService,
        private readonly CmsMaintenanceService $maintenanceService,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '/blog', name: 'public_blog_index', methods: ['GET'], priority: 5)]
    public function index(Request $request): Response
    {
        if (!$this->installerService->isLocked()) {
            return new RedirectResponse('/install');
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $maintenance = $this->maintenanceService->resolve($request, $site);
        if ($maintenance['active']) {
            return new Response($this->twig->render('public/maintenance.html.twig', [
                'message' => $maintenance['message'],
                'starts_at' => $maintenance['starts_at'],
                'ends_at' => $maintenance['ends_at'],
                'scope' => $maintenance['scope'],
            ]), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $posts = $this->postRepository->findBy(
            ['site' => $site, 'isPublished' => true],
            ['publishedAt' => 'DESC', 'updatedAt' => 'DESC']
        );

        return new Response($this->twig->render('public/blog/index.html.twig', [
            'posts' => $this->normalizePosts($posts),
            'cms_pages' => $this->normalizePages($site),
        ]));
    }

    #[Route(path: '/blog/{slug}', name: 'public_blog_show', methods: ['GET'], priority: 5)]
    public function show(Request $request, string $slug): Response
    {
        if (!$this->installerService->isLocked()) {
            return new RedirectResponse('/install');
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $maintenance = $this->maintenanceService->resolve($request, $site);
        if ($maintenance['active']) {
            return new Response($this->twig->render('public/maintenance.html.twig', [
                'message' => $maintenance['message'],
                'starts_at' => $maintenance['starts_at'],
                'ends_at' => $maintenance['ends_at'],
                'scope' => $maintenance['scope'],
            ]), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $post = $this->postRepository->findOneBy([
            'site' => $site,
            'slug' => $slug,
            'isPublished' => true,
        ]);

        if (!$post instanceof CmsPost) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('public/blog/show.html.twig', [
            'post' => $this->normalizePost($post),
            'cms_pages' => $this->normalizePages($site),
        ]));
    }

    /**
     * @param CmsPost[] $posts
     */
    private function normalizePosts(array $posts): array
    {
        return array_map(fn (CmsPost $post): array => $this->normalizePost($post), $posts);
    }

    private function normalizePost(CmsPost $post): array
    {
        return [
            'id' => $post->getId(),
            'title' => $post->getTitle(),
            'slug' => $post->getSlug(),
            'excerpt' => $post->getExcerpt(),
            'content' => $post->getContent(),
            'published_at' => $post->getPublishedAt(),
            'updated_at' => $post->getUpdatedAt(),
        ];
    }

    private function normalizePages(\App\Module\Core\Domain\Entity\Site $site): array
    {
        $pages = $this->pageRepository->findBy(
            ['isPublished' => true, 'site' => $site],
            ['title' => 'ASC']
        );

        return array_map(static fn ($page): array => [
            'title' => $page->getTitle(),
            'slug' => $page->getSlug(),
        ], $pages);
    }
}
