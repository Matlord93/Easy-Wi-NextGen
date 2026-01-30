<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Admin;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\CmsPost;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\User;
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
            'activeNav' => 'cms-blog',
        ]));
    }

    #[Route(path: '/new', name: 'admin_cms_blog_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        if (!$this->isCmsUser($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/cms/blog/form.html.twig', [
            'form' => $this->buildFormContext(),
            'activeNav' => 'cms-blog',
        ]));
    }

    #[Route(path: '', name: 'admin_cms_blog_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $formData = $this->parsePayload($request, $site);
        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST);
        }

        $post = new CmsPost(
            $site,
            $formData['title'],
            $formData['slug'],
            $formData['content'],
            $formData['excerpt'],
            $formData['is_published'],
        );
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
            'form' => $this->buildFormContext($post),
            'activeNav' => 'cms-blog',
        ]));
    }

    #[Route(path: '/{id}', name: 'admin_cms_blog_update', methods: ['POST'])]
    public function update(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User) {
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
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST, $post);
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
        if (!$actor instanceof User) {
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

        return $actor instanceof User;
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
        ], $posts);
    }

    private function buildFormContext(?CmsPost $post = null, ?array $overrides = null): array
    {
        $context = [
            'id' => $post?->getId(),
            'title' => $post?->getTitle() ?? '',
            'slug' => $post?->getSlug() ?? '',
            'excerpt' => $post?->getExcerpt() ?? '',
            'content' => $post?->getContent() ?? '',
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

        return [
            'errors' => $errors,
            'title' => $title,
            'slug' => $slug,
            'excerpt' => $excerpt !== '' ? $excerpt : null,
            'content' => $content,
            'is_published' => $isPublished,
        ];
    }

    private function renderFormWithErrors(array $formData, int $statusCode, ?CmsPost $post = null): Response
    {
        return new Response($this->twig->render('admin/cms/blog/form.html.twig', [
            'form' => $this->buildFormContext($post, [
                'title' => $formData['title'],
                'slug' => $formData['slug'],
                'excerpt' => $formData['excerpt'] ?? '',
                'content' => $formData['content'],
                'is_published' => $formData['is_published'],
                'errors' => $formData['errors'],
            ]),
            'activeNav' => 'cms-blog',
        ]), $statusCode);
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
