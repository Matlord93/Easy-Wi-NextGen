<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Public;

use App\Module\Cms\Application\CmsFeatureToggle;
use App\Module\Cms\Application\CmsMaintenanceService;
use App\Module\Cms\Application\CmsSettingsProvider;
use App\Module\Cms\Application\ThemeResolver;
use App\Module\Cms\UI\Http\MaintenancePageResponseFactory;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\BlogCategory;
use App\Module\Core\Domain\Entity\BlogTag;
use App\Module\Core\Domain\Entity\CmsPost;
use App\Module\Core\Domain\Entity\Site;
use App\Repository\BlogCategoryRepository;
use App\Repository\BlogTagRepository;
use App\Repository\CmsPageRepository;
use App\Repository\CmsPostRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/blog')]
final class PublicCmsBlogController
{
    public function __construct(
        private readonly CmsPostRepository $postRepository,
        private readonly BlogCategoryRepository $categoryRepository,
        private readonly BlogTagRepository $tagRepository,
        private readonly CmsPageRepository $pageRepository,
        private readonly SiteResolver $siteResolver,
        private readonly CmsFeatureToggle $featureToggle,
        private readonly CmsMaintenanceService $maintenanceService,
        private readonly MaintenancePageResponseFactory $maintenancePageResponseFactory,
        private readonly ThemeResolver $themeResolver,
        private readonly CmsSettingsProvider $settingsProvider,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'public_cms_blog_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $site = $this->siteResolver->resolve($request);
        if ($site === null || !$this->featureToggle->isEnabled($site, 'blog')) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $maintenance = $this->maintenanceService->resolve($request, $site);
        if ($maintenance['active']) {
            return $this->maintenancePageResponseFactory->create($maintenance);
        }

        $categorySlug = trim((string) $request->query->get('category', ''));
        $tagSlug = trim((string) $request->query->get('tag', ''));
        $query = trim((string) $request->query->get('q', ''));

        $category = $categorySlug !== '' ? $this->categoryRepository->findOneBySiteAndSlug($site, $categorySlug) : null;
        $tag = $tagSlug !== '' ? $this->tagRepository->findOneBySiteAndSlug($site, $tagSlug) : null;

        $posts = $this->postRepository->findPublishedByFilters($site, $category, $tag, $query !== '' ? $query : null);

        return new Response($this->twig->render('public/blog/index.html.twig', [
            'posts' => $this->normalizePosts($posts),
            'categories' => $this->normalizeCategories($this->categoryRepository->findBySite($site), $category),
            'tags' => $this->normalizeTags($this->tagRepository->findBySite($site), $tag),
            'filters' => [
                'q' => $query,
                'category' => $categorySlug,
                'tag' => $tagSlug,
            ],
            'cms_pages' => $this->normalizeCmsPages($site),
        ] + $this->themeContext($site, 'blog', 'Blog')));
    }

    #[Route(path: '/{slug}', name: 'public_cms_blog_show', methods: ['GET'])]
    public function show(Request $request, string $slug): Response
    {
        $site = $this->siteResolver->resolve($request);
        if ($site === null || !$this->featureToggle->isEnabled($site, 'blog')) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $maintenance = $this->maintenanceService->resolve($request, $site);
        if ($maintenance['active']) {
            return $this->maintenancePageResponseFactory->create($maintenance);
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
            'cms_pages' => $this->normalizeCmsPages($site),
        ] + $this->themeContext($site, 'blog', $post->getTitle())));
    }

    /** @param list<CmsPost> $posts @return list<array<string,mixed>> */
    private function normalizePosts(array $posts): array
    {
        return array_map(fn (CmsPost $post): array => $this->normalizePost($post), $posts);
    }

    /** @return array<string,mixed> */
    private function normalizePost(CmsPost $post): array
    {
        return [
            'id' => $post->getId(),
            'title' => $post->getTitle(),
            'slug' => $post->getSlug(),
            'excerpt' => $post->getExcerpt(),
            'content' => $post->getContent(),
            'seo_title' => $post->getSeoTitle(),
            'seo_description' => $post->getSeoDescription(),
            'featured_image_path' => $post->getFeaturedImagePath(),
            'published_at' => $post->getPublishedAt(),
            'category' => $post->getCategory() instanceof BlogCategory ? [
                'name' => $post->getCategory()?->getName(),
                'slug' => $post->getCategory()?->getSlug(),
            ] : null,
            'tags' => array_map(static fn (BlogTag $tag): array => [
                'name' => $tag->getName(),
                'slug' => $tag->getSlug(),
            ], $post->getTags()->toArray()),
        ];
    }

    /** @param list<BlogCategory> $categories @return list<array<string,mixed>> */
    private function normalizeCategories(array $categories, ?BlogCategory $active): array
    {
        return array_map(static fn (BlogCategory $category): array => [
            'name' => $category->getName(),
            'slug' => $category->getSlug(),
            'is_active' => $active instanceof BlogCategory && $active->getId() === $category->getId(),
        ], $categories);
    }

    /** @param list<BlogTag> $tags @return list<array<string,mixed>> */
    private function normalizeTags(array $tags, ?BlogTag $active): array
    {
        return array_map(static fn (BlogTag $tag): array => [
            'name' => $tag->getName(),
            'slug' => $tag->getSlug(),
            'is_active' => $active instanceof BlogTag && $active->getId() === $tag->getId(),
        ], $tags);
    }

    /** @return list<array{title:string,slug:string}> */
    private function normalizeCmsPages($site): array
    {
        $pages = $this->pageRepository->findBy(['site' => $site, 'isPublished' => true], ['title' => 'ASC']);

        return array_map(static fn ($page): array => [
            'title' => $page->getTitle(),
            'slug' => $page->getSlug(),
        ], $pages);
    }

    /** @return array<string,mixed> */
    private function themeContext(Site $site, string $slug, string $title): array
    {
        $templateKey = $this->themeResolver->resolveThemeKey($site);

        return [
            'active_theme' => $templateKey,
            'template_key' => $templateKey,
            'page' => ['slug' => $slug, 'title' => $title],
            'cms_navigation' => $this->settingsProvider->getNavigationLinks($site),
            'cms_footer_links' => $this->settingsProvider->getFooterLinks($site),
            'cms_branding' => $this->settingsProvider->getBranding($site),
        ];
    }
}
