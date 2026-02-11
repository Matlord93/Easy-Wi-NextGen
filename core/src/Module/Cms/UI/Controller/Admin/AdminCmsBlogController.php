<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Admin;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\BlogCategory;
use App\Module\Core\Domain\Entity\BlogTag;
use App\Module\Core\Domain\Entity\CmsPost;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\User;
use App\Repository\BlogCategoryRepository;
use App\Repository\BlogTagRepository;
use App\Repository\CmsPostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/cms/blog')]
final class AdminCmsBlogController
{
    public function __construct(
        private readonly CmsPostRepository $postRepository,
        private readonly BlogCategoryRepository $categoryRepository,
        private readonly BlogTagRepository $tagRepository,
        private readonly SiteResolver $siteResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_cms_blog_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isCmsUser($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $posts = $this->postRepository->findBy(['site' => $site], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/cms/blog/index.html.twig', [
            'posts' => $this->normalizePosts($posts),
            'categories' => $this->normalizeCategories($this->categoryRepository->findBySite($site)),
            'tags' => $this->normalizeTags($this->tagRepository->findBySite($site)),
            'activeNav' => 'cms-blog',
        ]));
    }

    #[Route(path: '/categories', name: 'admin_cms_blog_categories_create', methods: ['POST'])]
    public function createCategory(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if (!$site instanceof Site) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $name = trim((string) $request->request->get('name', ''));
        $slug = trim((string) $request->request->get('slug', ''));
        if ($name === '' || $slug === '') {
            return new RedirectResponse('/admin/cms/blog?category_error=1');
        }

        if ($this->categoryRepository->findOneBySiteAndSlug($site, $slug) instanceof BlogCategory) {
            return new RedirectResponse('/admin/cms/blog?category_error=duplicate');
        }

        $category = new BlogCategory($site, $name, $slug);
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'cms.blog.category.created', [
            'category_id' => $category->getId(),
            'site_id' => $site->getId(),
            'slug' => $slug,
        ]);
        $this->entityManager->flush();

        return new RedirectResponse('/admin/cms/blog');
    }

    #[Route(path: '/categories/{id}/delete', name: 'admin_cms_blog_categories_delete', methods: ['POST'])]
    public function deleteCategory(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if (!$site instanceof Site) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $category = $this->categoryRepository->find($id);
        if (!$category instanceof BlogCategory || $category->getSite()->getId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($category);
        $this->entityManager->flush();

        return new RedirectResponse('/admin/cms/blog');
    }

    #[Route(path: '/tags', name: 'admin_cms_blog_tags_create', methods: ['POST'])]
    public function createTag(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if (!$site instanceof Site) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $name = trim((string) $request->request->get('name', ''));
        $slug = trim((string) $request->request->get('slug', ''));
        if ($name === '' || $slug === '') {
            return new RedirectResponse('/admin/cms/blog?tag_error=1');
        }

        if ($this->tagRepository->findOneBySiteAndSlug($site, $slug) instanceof BlogTag) {
            return new RedirectResponse('/admin/cms/blog?tag_error=duplicate');
        }

        $tag = new BlogTag($site, $name, $slug);
        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'cms.blog.tag.created', [
            'tag_id' => $tag->getId(),
            'site_id' => $site->getId(),
            'slug' => $slug,
        ]);
        $this->entityManager->flush();

        return new RedirectResponse('/admin/cms/blog');
    }

    #[Route(path: '/tags/{id}/delete', name: 'admin_cms_blog_tags_delete', methods: ['POST'])]
    public function deleteTag(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if (!$site instanceof Site) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $tag = $this->tagRepository->find($id);
        if (!$tag instanceof BlogTag || $tag->getSite()->getId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($tag);
        $this->entityManager->flush();

        return new RedirectResponse('/admin/cms/blog');
    }

    #[Route(path: '/new', name: 'admin_cms_blog_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        if (!$this->isCmsUser($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if (!$site instanceof Site) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('admin/cms/blog/form.html.twig', [
            'form' => $this->buildFormContext(site: $site),
            'activeNav' => 'cms-blog',
        ]));
    }

    #[Route(path: '', name: 'admin_cms_blog_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $formData = $this->parsePayload($request, $site);
        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST, site: $site);
        }

        $post = new CmsPost(
            $site,
            $formData['title'],
            $formData['slug'],
            $formData['content'],
            $formData['excerpt'],
            $formData['is_published'],
        );
        $this->applyBlogV2Fields($post, $site, $formData);

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'cms.blog.created', [
            'post_id' => $post->getId(),
            'site_id' => $site->getId(),
            'title' => $post->getTitle(),
            'slug' => $post->getSlug(),
        ]);
        $this->entityManager->flush();

        return new RedirectResponse('/admin/cms/blog');
    }

    #[Route(path: '/{id}/edit', name: 'admin_cms_blog_edit', methods: ['GET'])]
    public function edit(Request $request, int $id): Response
    {
        if (!$this->isCmsUser($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $post = $this->postRepository->find($id);
        if (!$post instanceof CmsPost || $post->getSite()->getId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('admin/cms/blog/form.html.twig', [
            'form' => $this->buildFormContext($post, site: $site),
            'activeNav' => 'cms-blog',
        ]));
    }

    #[Route(path: '/{id}', name: 'admin_cms_blog_update', methods: ['POST'])]
    public function update(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $post = $this->postRepository->find($id);
        if (!$post instanceof CmsPost || $post->getSite()->getId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $formData = $this->parsePayload($request, $site, $post);
        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST, $post, $site);
        }

        $previous = [
            'title' => $post->getTitle(),
            'slug' => $post->getSlug(),
            'is_published' => $post->isPublished(),
        ];

        $post->setTitle($formData['title']);
        $post->setSlug($formData['slug']);
        $post->setExcerpt($formData['excerpt']);
        $post->setContent($formData['content']);
        $post->setPublished($formData['is_published']);
        $this->applyBlogV2Fields($post, $site, $formData);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'cms.blog.updated', [
            'post_id' => $post->getId(),
            'site_id' => $site->getId(),
            'previous' => $previous,
            'current' => [
                'title' => $post->getTitle(),
                'slug' => $post->getSlug(),
                'is_published' => $post->isPublished(),
            ],
        ]);
        $this->entityManager->flush();

        return new RedirectResponse('/admin/cms/blog');
    }

    #[Route(path: '/{id}/delete', name: 'admin_cms_blog_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $post = $this->postRepository->find($id);
        if (!$post instanceof CmsPost || $post->getSite()->getId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $this->auditLogger->log($actor, 'cms.blog.deleted', [
            'post_id' => $post->getId(),
            'site_id' => $site->getId(),
            'title' => $post->getTitle(),
            'slug' => $post->getSlug(),
        ]);

        $this->entityManager->remove($post);
        $this->entityManager->flush();

        return new RedirectResponse('/admin/cms/blog');
    }

    private function isCmsUser(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->isAdmin();
    }

    /**
     * @param array<int, CmsPost> $posts
     */
    private function normalizePosts(array $posts): array
    {
        return array_map(static fn (CmsPost $post): array => [
            'id' => $post->getId(),
            'title' => $post->getTitle(),
            'slug' => $post->getSlug(),
            'excerpt' => $post->getExcerpt(),
            'is_published' => $post->isPublished(),
            'published_at' => $post->getPublishedAt(),
            'updated_at' => $post->getUpdatedAt(),
            'category' => $post->getCategory()?->getName(),
            'tags' => array_map(static fn (BlogTag $tag): string => $tag->getName(), $post->getTags()->toArray()),
        ], $posts);
    }

    /**
     * @param list<BlogCategory> $categories
     * @return list<array{id:int|null,name:string,slug:string}>
     */
    private function normalizeCategories(array $categories): array
    {
        return array_map(static fn (BlogCategory $category): array => [
            'id' => $category->getId(),
            'name' => $category->getName(),
            'slug' => $category->getSlug(),
        ], $categories);
    }

    /**
     * @param list<BlogTag> $tags
     * @return list<array{id:int|null,name:string,slug:string}>
     */
    private function normalizeTags(array $tags): array
    {
        return array_map(static fn (BlogTag $tag): array => [
            'id' => $tag->getId(),
            'name' => $tag->getName(),
            'slug' => $tag->getSlug(),
        ], $tags);
    }

    private function buildFormContext(?CmsPost $post = null, ?array $overrides = null, ?Site $site = null): array
    {
        $site ??= $post?->getSite();
        $categories = $site instanceof Site ? $this->categoryRepository->findBySite($site) : [];
        $tags = $site instanceof Site ? $this->tagRepository->findBySite($site) : [];

        $context = [
            'id' => $post?->getId(),
            'title' => $post?->getTitle() ?? '',
            'slug' => $post?->getSlug() ?? '',
            'excerpt' => $post?->getExcerpt() ?? '',
            'content' => $post?->getContent() ?? '',
            'seo_title' => $post?->getSeoTitle() ?? '',
            'seo_description' => $post?->getSeoDescription() ?? '',
            'featured_image_path' => $post?->getFeaturedImagePath() ?? '',
            'category_id' => $post?->getCategory()?->getId(),
            'tag_ids' => array_map(static fn (BlogTag $tag): int => (int) $tag->getId(), $post?->getTags()->toArray() ?? []),
            'categories' => $this->normalizeCategories($categories),
            'available_tags' => $this->normalizeTags($tags),
            'is_published' => $post?->isPublished() ?? false,
            'errors' => [],
            'action' => $post === null ? 'create' : 'update',
            'submit_label' => $post === null ? 'cms_blog_create' : 'cms_blog_update',
            'action_url' => $post === null ? '/admin/cms/blog' : sprintf('/admin/cms/blog/%d', $post->getId()),
        ];

        if ($overrides !== null) {
            $context = array_merge($context, $overrides);
        }

        return $context;
    }

    private function parsePayload(Request $request, Site $site, ?CmsPost $existingPost = null): array
    {
        $errors = [];
        $title = trim((string) $request->request->get('title', ''));
        $slug = trim((string) $request->request->get('slug', ''));
        $excerpt = trim((string) $request->request->get('excerpt', ''));
        $content = trim((string) $request->request->get('content', ''));
        $seoTitle = trim((string) $request->request->get('seo_title', ''));
        $seoDescription = trim((string) $request->request->get('seo_description', ''));
        $featuredImagePath = trim((string) $request->request->get('featured_image_path', ''));
        $categoryIdRaw = $request->request->get('category_id');
        $tagIdsRaw = $request->request->all('tag_ids');
        $isPublished = $request->request->get('is_published') === 'on';

        if ($title === '') {
            $errors[] = 'Title is required.';
        }
        if ($slug === '') {
            $errors[] = 'Slug is required.';
        }
        if ($slug !== '' && $this->isDuplicateSlug($site, $slug, $existingPost)) {
            $errors[] = 'Slug is already in use for this site.';
        }
        if ($content === '') {
            $errors[] = 'Content is required.';
        }

        $categoryId = is_numeric($categoryIdRaw) ? (int) $categoryIdRaw : null;
        $tagIds = array_values(array_unique(array_map(static fn ($id): int => (int) $id, array_filter($tagIdsRaw, static fn ($id): bool => is_numeric($id)))));

        return [
            'errors' => $errors,
            'title' => $title,
            'slug' => $slug,
            'excerpt' => $excerpt !== '' ? $excerpt : null,
            'content' => $content,
            'seo_title' => $seoTitle !== '' ? $seoTitle : null,
            'seo_description' => $seoDescription !== '' ? $seoDescription : null,
            'featured_image_path' => $featuredImagePath !== '' ? $featuredImagePath : null,
            'category_id' => $categoryId,
            'tag_ids' => $tagIds,
            'is_published' => $isPublished,
        ];
    }

    private function renderFormWithErrors(array $formData, int $statusCode, ?CmsPost $post = null, ?Site $site = null): Response
    {
        return new Response($this->twig->render('admin/cms/blog/form.html.twig', [
            'form' => $this->buildFormContext($post, [
                'title' => $formData['title'],
                'slug' => $formData['slug'],
                'excerpt' => $formData['excerpt'] ?? '',
                'content' => $formData['content'],
                'seo_title' => $formData['seo_title'] ?? '',
                'seo_description' => $formData['seo_description'] ?? '',
                'featured_image_path' => $formData['featured_image_path'] ?? '',
                'category_id' => $formData['category_id'] ?? null,
                'tag_ids' => $formData['tag_ids'] ?? [],
                'is_published' => $formData['is_published'],
                'errors' => $formData['errors'],
            ], $site),
            'activeNav' => 'cms-blog',
        ]), $statusCode);
    }

    /**
     * @param array<string,mixed> $formData
     */
    private function applyBlogV2Fields(CmsPost $post, Site $site, array $formData): void
    {
        $post->setSeoTitle($formData['seo_title']);
        $post->setSeoDescription($formData['seo_description']);
        $post->setFeaturedImagePath($formData['featured_image_path']);

        $category = null;
        if (is_int($formData['category_id'])) {
            $found = $this->categoryRepository->find($formData['category_id']);
            if ($found instanceof BlogCategory && $found->getSite()->getId() === $site->getId()) {
                $category = $found;
            }
        }
        $post->setCategory($category);

        $post->clearTags();
        foreach (($formData['tag_ids'] ?? []) as $tagId) {
            if (!is_int($tagId)) {
                continue;
            }
            $tag = $this->tagRepository->find($tagId);
            if ($tag instanceof BlogTag && $tag->getSite()->getId() === $site->getId()) {
                $post->addTag($tag);
            }
        }
    }

    private function isDuplicateSlug(Site $site, string $slug, ?CmsPost $existingPost): bool
    {
        $existing = $this->postRepository->findOneBy([
            'site' => $site,
            'slug' => $slug,
        ]);

        if (!$existing instanceof CmsPost) {
            return false;
        }

        if ($existingPost === null) {
            return true;
        }

        return $existing->getId() !== $existingPost->getId();
    }
}
