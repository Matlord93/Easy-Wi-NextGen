<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\KnowledgeBaseArticle;
use App\Entity\User;
use App\Enum\TicketCategory;
use App\Enum\UserType;
use App\Repository\KnowledgeBaseArticleRepository;
use App\Service\AuditLogger;
use App\Service\SiteResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/docs')]
final class AdminKnowledgeBaseController
{
    public function __construct(
        private readonly KnowledgeBaseArticleRepository $kbRepository,
        private readonly SiteResolver $siteResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_docs', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $articles = $this->kbRepository->findBy(['siteId' => $site->getId()], ['category' => 'ASC', 'title' => 'ASC']);

        return new Response($this->twig->render('admin/docs/index.html.twig', [
            'articles' => $this->normalizeArticles($articles),
            'summary' => $this->buildSummary($articles),
            'form' => $this->buildFormContext(),
            'categories' => $this->categories(),
            'activeNav' => 'docs',
        ]));
    }

    #[Route(path: '/table', name: 'admin_docs_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $articles = $this->kbRepository->findBy(['siteId' => $site->getId()], ['category' => 'ASC', 'title' => 'ASC']);

        return new Response($this->twig->render('admin/docs/_table.html.twig', [
            'articles' => $this->normalizeArticles($articles),
        ]));
    }

    #[Route(path: '/form', name: 'admin_docs_form', methods: ['GET'])]
    public function form(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/docs/_form.html.twig', [
            'form' => $this->buildFormContext(),
            'categories' => $this->categories(),
        ]));
    }

    #[Route(path: '/{id}/edit', name: 'admin_docs_edit', methods: ['GET'])]
    public function edit(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $article = $this->kbRepository->find($id);
        if ($article === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $article->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('admin/docs/_form.html.twig', [
            'form' => $this->buildFormContext($article),
            'categories' => $this->categories(),
        ]));
    }

    #[Route(path: '', name: 'admin_docs_create', methods: ['POST'])]
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

        $formData = $this->parsePayload($request, $site->getId() ?? 0);
        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST);
        }

        $article = new KnowledgeBaseArticle(
            siteId: $site->getId() ?? 0,
            title: $formData['title'],
            slug: $formData['slug'],
            content: $formData['content'],
            category: $formData['category'],
            visiblePublic: $formData['visible_public'],
        );

        $this->entityManager->persist($article);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'knowledge_base.created', [
            'article_id' => $article->getId(),
            'site_id' => $article->getSiteId(),
            'title' => $article->getTitle(),
            'slug' => $article->getSlug(),
            'category' => $article->getCategory()->value,
            'visible_public' => $article->isVisiblePublic(),
        ]);
        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/docs/_form.html.twig', [
            'form' => $this->buildFormContext(),
            'categories' => $this->categories(),
        ]));
        $response->headers->set('HX-Trigger', 'docs-changed');

        return $response;
    }

    #[Route(path: '/{id}', name: 'admin_docs_update', methods: ['POST'])]
    public function update(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $article = $this->kbRepository->find($id);
        if ($article === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $article->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $formData = $this->parsePayload($request, $site->getId() ?? 0, $article->getId());
        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST, $article);
        }

        $previous = [
            'title' => $article->getTitle(),
            'slug' => $article->getSlug(),
            'category' => $article->getCategory()->value,
            'visible_public' => $article->isVisiblePublic(),
        ];

        $article->setTitle($formData['title']);
        $article->setSlug($formData['slug']);
        $article->setContent($formData['content']);
        $article->setCategory($formData['category']);
        $article->setVisiblePublic($formData['visible_public']);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'knowledge_base.updated', [
            'article_id' => $article->getId(),
            'site_id' => $article->getSiteId(),
            'previous' => $previous,
            'current' => [
                'title' => $article->getTitle(),
                'slug' => $article->getSlug(),
                'category' => $article->getCategory()->value,
                'visible_public' => $article->isVisiblePublic(),
            ],
        ]);
        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/docs/_form.html.twig', [
            'form' => $this->buildFormContext(),
            'categories' => $this->categories(),
        ]));
        $response->headers->set('HX-Trigger', 'docs-changed');

        return $response;
    }

    #[Route(path: '/{id}/delete', name: 'admin_docs_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $article = $this->kbRepository->find($id);
        if ($article === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $article->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $this->auditLogger->log($actor, 'knowledge_base.deleted', [
            'article_id' => $article->getId(),
            'site_id' => $article->getSiteId(),
            'title' => $article->getTitle(),
        ]);

        $this->entityManager->remove($article);
        $this->entityManager->flush();

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->headers->set('HX-Trigger', 'docs-changed');

        return $response;
    }

    private function parsePayload(Request $request, int $siteId, ?int $ignoreId = null): array
    {
        $errors = [];

        $title = trim((string) $request->request->get('title', ''));
        $slug = trim((string) $request->request->get('slug', ''));
        $content = trim((string) $request->request->get('content', ''));
        $categoryValue = trim((string) $request->request->get('category', ''));
        $visiblePublic = $request->request->get('visible_public') === 'on';

        if ($title === '') {
            $errors[] = 'Title is required.';
        }
        if ($slug === '') {
            $errors[] = 'Slug is required.';
        }
        if ($content === '') {
            $errors[] = 'Content is required.';
        }

        $category = TicketCategory::tryFrom($categoryValue);
        if ($category === null) {
            $errors[] = 'Category is required.';
            $category = TicketCategory::General;
        }

        if ($slug !== '' && !$this->isSlugAvailable($slug, $siteId, $ignoreId)) {
            $errors[] = 'Slug must be unique.';
        }

        return [
            'errors' => $errors,
            'title' => $title,
            'slug' => $slug,
            'content' => $content,
            'category' => $category,
            'visible_public' => $visiblePublic,
        ];
    }

    private function isSlugAvailable(string $slug, int $siteId, ?int $ignoreId = null): bool
    {
        $existing = $this->kbRepository->findOneBy(['slug' => $slug, 'siteId' => $siteId]);
        if ($existing === null) {
            return true;
        }

        return $ignoreId !== null && $existing->getId() === $ignoreId;
    }

    private function buildFormContext(?KnowledgeBaseArticle $article = null, ?array $override = null): array
    {
        $data = [
            'id' => $article?->getId(),
            'title' => $article?->getTitle() ?? '',
            'slug' => $article?->getSlug() ?? '',
            'content' => $article?->getContent() ?? '',
            'category' => $article?->getCategory()->value ?? TicketCategory::General->value,
            'visible_public' => $article?->isVisiblePublic() ?? false,
            'errors' => [],
            'action' => $article === null ? 'create' : 'update',
            'submit_label' => $article === null ? 'admin_docs_create_submit' : 'admin_docs_update_submit',
            'submit_color' => $article === null ? 'bg-indigo-600' : 'bg-amber-500',
            'action_url' => $article === null ? '/admin/docs' : sprintf('/admin/docs/%d', $article->getId()),
        ];

        if ($override !== null) {
            $data = array_merge($data, $override);
        }

        return $data;
    }

    private function renderFormWithErrors(array $formData, int $status, ?KnowledgeBaseArticle $article = null): Response
    {
        $formContext = $this->buildFormContext($article, [
            'title' => $formData['title'],
            'slug' => $formData['slug'],
            'content' => $formData['content'],
            'category' => $formData['category']->value,
            'visible_public' => $formData['visible_public'],
            'errors' => $formData['errors'],
        ]);

        return new Response($this->twig->render('admin/docs/_form.html.twig', [
            'form' => $formContext,
            'categories' => $this->categories(),
        ]), $status);
    }

    /**
     * @param KnowledgeBaseArticle[] $articles
     */
    private function buildSummary(array $articles): array
    {
        $summary = [
            'total' => count($articles),
            'public' => 0,
            'hidden' => 0,
        ];

        foreach ($articles as $article) {
            if ($article->isVisiblePublic()) {
                $summary['public']++;
            } else {
                $summary['hidden']++;
            }
        }

        return $summary;
    }

    /**
     * @param KnowledgeBaseArticle[] $articles
     */
    private function normalizeArticles(array $articles): array
    {
        return array_map(static function (KnowledgeBaseArticle $article): array {
            return [
                'id' => $article->getId(),
                'title' => $article->getTitle(),
                'slug' => $article->getSlug(),
                'content' => $article->getContent(),
                'category' => $article->getCategory()->value,
                'visible_public' => $article->isVisiblePublic(),
                'updated_at' => $article->getUpdatedAt(),
            ];
        }, $articles);
    }

    /**
     * @return array<string, string>
     */
    private function categories(): array
    {
        return [
            TicketCategory::General->value => 'General',
            TicketCategory::Billing->value => 'Billing',
            TicketCategory::Technical->value => 'Technical',
            TicketCategory::Abuse->value => 'Abuse',
        ];
    }

    private function isAdmin(Request $request): bool
    {
        $user = $request->attributes->get('current_user');

        return $user instanceof User && $user->isAdmin();
    }
}
