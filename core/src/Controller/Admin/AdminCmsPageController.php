<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CmsBlock;
use App\Entity\CmsPage;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\CmsBlockRepository;
use App\Repository\CmsPageRepository;
use App\Service\AuditLogger;
use App\Service\CmsTemplateCatalog;
use App\Service\SiteResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/cms/pages')]
final class AdminCmsPageController
{
    public function __construct(
        private readonly CmsPageRepository $pageRepository,
        private readonly CmsBlockRepository $blockRepository,
        private readonly SiteResolver $siteResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly CmsTemplateCatalog $cmsTemplateCatalog,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_cms_pages', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $pages = $this->pageRepository->findBy(['site' => $site], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/cms/pages/index.html.twig', [
            'pages' => $this->normalizePages($pages),
            'summary' => $this->buildSummary($pages),
            'form' => $this->buildFormContext(),
            'templateForm' => $this->buildTemplateFormContext($site->getCmsTemplateKey()),
            'templates' => $this->cmsTemplateCatalog->listTemplates(),
            'activeNav' => 'cms-pages',
        ]));
    }

    #[Route(path: '/table', name: 'admin_cms_pages_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $pages = $this->pageRepository->findBy(['site' => $site], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/cms/pages/_table.html.twig', [
            'pages' => $this->normalizePages($pages),
        ]));
    }

    #[Route(path: '/form', name: 'admin_cms_pages_form', methods: ['GET'])]
    public function form(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/cms/pages/_form.html.twig', [
            'form' => $this->buildFormContext(),
        ]));
    }

    #[Route(path: '/{id}/edit', name: 'admin_cms_pages_edit', methods: ['GET'])]
    public function edit(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $page = $this->pageRepository->find($id);
        if (!$page instanceof CmsPage || $page->getSite()->getId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('admin/cms/pages/_form.html.twig', [
            'form' => $this->buildFormContext($page),
        ]));
    }

    #[Route(path: '', name: 'admin_cms_pages_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
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

        $page = new CmsPage($site, $formData['title'], $formData['slug'], $formData['is_published']);
        $this->entityManager->persist($page);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'cms.page.created', [
            'page_id' => $page->getId(),
            'site_id' => $page->getSite()->getId(),
            'title' => $page->getTitle(),
            'slug' => $page->getSlug(),
            'is_published' => $page->isPublished(),
        ]);
        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/cms/pages/_form.html.twig', [
            'form' => $this->buildFormContext(),
        ]));
        $response->headers->set('HX-Trigger', 'cms-pages-changed');

        return $response;
    }

    #[Route(path: '/{id}', name: 'admin_cms_pages_update', methods: ['POST'])]
    public function update(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $page = $this->pageRepository->find($id);
        if (!$page instanceof CmsPage || $page->getSite()->getId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $formData = $this->parsePayload($request, $site, $page);
        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST, $page);
        }

        $previous = [
            'title' => $page->getTitle(),
            'slug' => $page->getSlug(),
            'is_published' => $page->isPublished(),
        ];

        $page->setTitle($formData['title']);
        $page->setSlug($formData['slug']);
        $page->setPublished($formData['is_published']);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'cms.page.updated', [
            'page_id' => $page->getId(),
            'site_id' => $page->getSite()->getId(),
            'previous' => $previous,
            'current' => [
                'title' => $page->getTitle(),
                'slug' => $page->getSlug(),
                'is_published' => $page->isPublished(),
            ],
        ]);
        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/cms/pages/_form.html.twig', [
            'form' => $this->buildFormContext(),
        ]));
        $response->headers->set('HX-Trigger', 'cms-pages-changed');

        return $response;
    }

    #[Route(path: '/{id}/delete', name: 'admin_cms_pages_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $page = $this->pageRepository->find($id);
        if (!$page instanceof CmsPage || $page->getSite()->getId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $this->auditLogger->log($actor, 'cms.page.deleted', [
            'page_id' => $page->getId(),
            'site_id' => $page->getSite()->getId(),
            'title' => $page->getTitle(),
            'slug' => $page->getSlug(),
        ]);

        $this->entityManager->remove($page);
        $this->entityManager->flush();

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->headers->set('HX-Trigger', 'cms-pages-changed');

        return $response;
    }

    #[Route(path: '/template', name: 'admin_cms_pages_template', methods: ['POST'])]
    public function updateTemplate(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $formData = $this->parseTemplatePayload($request);
        if ($formData['errors'] !== []) {
            return $this->renderTemplateFormWithErrors($formData, Response::HTTP_BAD_REQUEST);
        }

        $template = $this->cmsTemplateCatalog->getTemplate($formData['template_key']);
        if ($template === null) {
            return $this->renderTemplateFormWithErrors([
                'errors' => ['Selected CMS template is invalid.'],
                'template_key' => $formData['template_key'],
                'apply_template' => $formData['apply_template'],
            ], Response::HTTP_BAD_REQUEST);
        }

        $site->setCmsTemplateKey($formData['template_key']);

        if ($formData['apply_template']) {
            $this->applyTemplate($site, $template);
        }

        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'cms.template.selected', [
            'site_id' => $site->getId(),
            'template_key' => $formData['template_key'],
            'applied' => $formData['apply_template'],
        ]);
        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/cms/pages/_template_form.html.twig', [
            'templateForm' => $this->buildTemplateFormContext($site->getCmsTemplateKey(), [
                'success' => true,
            ]),
            'templates' => $this->cmsTemplateCatalog->listTemplates(),
        ]));
        $response->headers->set('HX-Trigger', 'cms-pages-changed');

        return $response;
    }

    #[Route(path: '/{id}', name: 'admin_cms_pages_show', methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $page = $this->pageRepository->find($id);
        if (!$page instanceof CmsPage || $page->getSite()->getId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $blocks = $this->blockRepository->findBy(['page' => $page], ['sortOrder' => 'ASC']);

        return new Response($this->twig->render('admin/cms/pages/show.html.twig', [
            'page' => $this->normalizePage($page),
            'blocks' => $this->normalizeBlocks($blocks),
            'blockForm' => $this->buildBlockFormContext(),
            'activeNav' => 'cms-pages',
        ]));
    }

    #[Route(path: '/{id}/blocks/table', name: 'admin_cms_pages_blocks_table', methods: ['GET'])]
    public function blocksTable(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $page = $this->pageRepository->find($id);
        if (!$page instanceof CmsPage || $page->getSite()->getId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $blocks = $this->blockRepository->findBy(['page' => $page], ['sortOrder' => 'ASC']);

        return new Response($this->twig->render('admin/cms/pages/_blocks_table.html.twig', [
            'blocks' => $this->normalizeBlocks($blocks),
            'page' => $this->normalizePage($page),
        ]));
    }

    #[Route(path: '/{id}/blocks/form', name: 'admin_cms_pages_blocks_form', methods: ['GET'])]
    public function blockForm(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $page = $this->pageRepository->find($id);
        if (!$page instanceof CmsPage || $page->getSite()->getId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('admin/cms/pages/_block_form.html.twig', [
            'blockForm' => $this->buildBlockFormContext(),
            'page' => $this->normalizePage($page),
        ]));
    }

    #[Route(path: '/{id}/blocks/{blockId}/edit', name: 'admin_cms_pages_blocks_edit', methods: ['GET'])]
    public function editBlock(Request $request, int $id, int $blockId): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $page = $this->pageRepository->find($id);
        if (!$page instanceof CmsPage || $page->getSite()->getId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $block = $this->blockRepository->find($blockId);
        if (!$block instanceof CmsBlock || $block->getPage()->getId() !== $page->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('admin/cms/pages/_block_form.html.twig', [
            'blockForm' => $this->buildBlockFormContextFromBlock($block),
            'page' => $this->normalizePage($page),
        ]));
    }

    #[Route(path: '/{id}/blocks', name: 'admin_cms_pages_blocks_create', methods: ['POST'])]
    public function createBlock(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $page = $this->pageRepository->find($id);
        if (!$page instanceof CmsPage || $page->getSite()->getId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $formData = $this->parseBlockPayload($request);
        if ($formData['errors'] !== []) {
            $formData['page'] = $this->normalizePage($page);
            return $this->renderBlockFormWithErrors($formData, Response::HTTP_BAD_REQUEST);
        }

        $sortOrder = $this->blockRepository->count(['page' => $page]) + 1;
        $block = new CmsBlock($page, $formData['type'], $formData['content'], $sortOrder);
        $page->addBlock($block);

        $this->entityManager->persist($block);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'cms.block.created', [
            'page_id' => $page->getId(),
            'site_id' => $page->getSite()->getId(),
            'block_id' => $block->getId(),
            'type' => $block->getType(),
            'sort_order' => $block->getSortOrder(),
        ]);
        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/cms/pages/_block_form.html.twig', [
            'blockForm' => $this->buildBlockFormContext(),
            'page' => $this->normalizePage($page),
        ]));
        $response->headers->set('HX-Trigger', 'cms-blocks-changed');

        return $response;
    }

    #[Route(path: '/{id}/blocks/{blockId}', name: 'admin_cms_pages_blocks_update', methods: ['POST'])]
    public function updateBlock(Request $request, int $id, int $blockId): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $page = $this->pageRepository->find($id);
        if (!$page instanceof CmsPage || $page->getSite()->getId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $block = $this->blockRepository->find($blockId);
        if (!$block instanceof CmsBlock || $block->getPage()->getId() !== $page->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $formData = $this->parseBlockPayload($request);
        if ($formData['errors'] !== []) {
            $formData['page'] = $this->normalizePage($page);
            $formData['block_id'] = $blockId;
            return $this->renderBlockFormWithErrors($formData, Response::HTTP_BAD_REQUEST);
        }

        $previous = [
            'type' => $block->getType(),
            'content' => $block->getContent(),
        ];

        $block->setType($formData['type']);
        $block->setContent($formData['content']);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'cms.block.updated', [
            'page_id' => $page->getId(),
            'site_id' => $page->getSite()->getId(),
            'block_id' => $block->getId(),
            'previous' => $previous,
            'current' => [
                'type' => $block->getType(),
                'content' => $block->getContent(),
            ],
        ]);
        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/cms/pages/_block_form.html.twig', [
            'blockForm' => $this->buildBlockFormContext(),
            'page' => $this->normalizePage($page),
        ]));
        $response->headers->set('HX-Trigger', 'cms-blocks-changed');

        return $response;
    }

    #[Route(path: '/{id}/blocks/{blockId}/delete', name: 'admin_cms_pages_blocks_delete', methods: ['POST'])]
    public function deleteBlock(Request $request, int $id, int $blockId): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $page = $this->pageRepository->find($id);
        if (!$page instanceof CmsPage || $page->getSite()->getId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $block = $this->blockRepository->find($blockId);
        if (!$block instanceof CmsBlock || $block->getPage()->getId() !== $page->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $this->auditLogger->log($actor, 'cms.block.deleted', [
            'page_id' => $page->getId(),
            'site_id' => $page->getSite()->getId(),
            'block_id' => $block->getId(),
            'type' => $block->getType(),
        ]);

        $this->entityManager->remove($block);
        $this->entityManager->flush();

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->headers->set('HX-Trigger', 'cms-blocks-changed');

        return $response;
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->getType() === UserType::Admin;
    }

    private function buildSummary(array $pages): array
    {
        $published = 0;
        $blocks = 0;
        foreach ($pages as $page) {
            if ($page->isPublished()) {
                $published++;
            }
            $blocks += $page->getBlocks()->count();
        }

        return [
            'total' => count($pages),
            'published' => $published,
            'blocks' => $blocks,
        ];
    }

    private function normalizePages(array $pages): array
    {
        return array_map(fn (CmsPage $page) => $this->normalizePage($page), $pages);
    }

    private function normalizePage(CmsPage $page): array
    {
        return [
            'id' => $page->getId(),
            'title' => $page->getTitle(),
            'slug' => $page->getSlug(),
            'is_published' => $page->isPublished(),
            'block_count' => $page->getBlocks()->count(),
            'updated_at' => $page->getUpdatedAt(),
        ];
    }

    private function normalizeBlocks(array $blocks): array
    {
        return array_map(function (CmsBlock $block): array {
            $preview = $block->getContent();
            if (in_array($block->getType(), ['server_list', 'server_featured'], true)) {
                $preview = $this->buildServerBlockPreview($block->getContent());
            }

            return [
                'id' => $block->getId(),
                'type' => $block->getType(),
                'content' => $block->getContent(),
                'preview' => $preview,
                'sort_order' => $block->getSortOrder(),
                'updated_at' => $block->getUpdatedAt(),
            ];
        }, $blocks);
    }

    private function buildFormContext(?CmsPage $page = null, ?array $overrides = null): array
    {
        $context = [
            'id' => $page?->getId(),
            'title' => $page?->getTitle() ?? '',
            'slug' => $page?->getSlug() ?? '',
            'is_published' => $page?->isPublished() ?? false,
            'errors' => [],
            'action' => $page === null ? 'create' : 'update',
            'submit_label' => $page === null ? 'create_page' : 'update_page',
            'submit_color' => $page === null ? 'bg-indigo-600 hover:bg-indigo-700' : 'bg-amber-500 hover:bg-amber-600',
            'action_url' => $page === null ? '/admin/cms/pages' : sprintf('/admin/cms/pages/%d', $page->getId()),
            'header' => $page === null ? 'create_cms_page' : 'edit_cms_page',
            'description' => $page === null ? 'create_cms_page_description' : 'edit_cms_page_description',
        ];

        if ($overrides !== null) {
            $context = array_merge($context, $overrides);
        }

        return $context;
    }

    private function buildBlockFormContext(?array $overrides = null): array
    {
        $defaults = [
            'id' => null,
            'errors' => [],
            'type' => '',
            'content' => '',
            'settings' => [
                'game' => '',
                'limit' => 5,
                'show_players' => true,
                'show_join_button' => false,
            ],
            'action' => 'create',
            'submit_label' => 'add_block',
            'submit_color' => 'bg-indigo-600 hover:bg-indigo-700',
        ];

        return array_merge($defaults, $overrides ?? []);
    }

    private function buildBlockFormContextFromBlock(CmsBlock $block): array
    {
        $settings = [
            'game' => '',
            'limit' => 5,
            'show_players' => true,
            'show_join_button' => false,
        ];

        $content = $block->getContent();
        if (in_array($block->getType(), ['server_list', 'server_featured'], true)) {
            $settings = $this->decodeBlockSettings($content);
            $content = '';
        }

        return $this->buildBlockFormContext([
            'id' => $block->getId(),
            'type' => $block->getType(),
            'content' => $content,
            'settings' => $settings,
            'action' => 'update',
            'submit_label' => 'update_block',
            'submit_color' => 'bg-amber-500 hover:bg-amber-600',
        ]);
    }

    private function buildTemplateFormContext(?string $templateKey, ?array $overrides = null): array
    {
        $defaults = [
            'errors' => [],
            'template_key' => $templateKey ?? '',
            'apply_template' => true,
            'success' => false,
        ];

        return array_merge($defaults, $overrides ?? []);
    }

    private function parsePayload(Request $request, \App\Entity\Site $site, ?CmsPage $existingPage = null): array
    {
        $errors = [];
        $title = trim((string) $request->request->get('title', ''));
        $slug = trim((string) $request->request->get('slug', ''));
        $isPublished = $request->request->get('is_published') === 'on';

        if ($title === '') {
            $errors[] = 'Title is required.';
        }
        if ($slug === '') {
            $errors[] = 'Slug is required.';
        }
        if ($slug !== '' && $this->isDuplicateSlug($site, $slug, $existingPage)) {
            $errors[] = 'Slug is already in use for this site.';
        }

        return [
            'errors' => $errors,
            'title' => $title,
            'slug' => $slug,
            'is_published' => $isPublished,
        ];
    }

    private function parseBlockPayload(Request $request): array
    {
        $errors = [];
        $type = trim((string) $request->request->get('type', ''));
        $content = trim((string) $request->request->get('content', ''));

        if ($type === '') {
            $errors[] = 'Block type is required.';
        }

        $isServerBlock = in_array($type, ['server_list', 'server_featured'], true);
        $settings = [
            'game' => trim((string) $request->request->get('server_game', '')),
            'limit' => $request->request->get('server_limit'),
            'show_players' => $request->request->get('server_show_players') === 'on',
            'show_join_button' => $request->request->get('server_show_join_button') === 'on',
        ];

        if ($isServerBlock) {
            $limitValue = is_numeric($settings['limit']) ? (int) $settings['limit'] : null;
            if ($limitValue === null) {
                $limitValue = $type === 'server_featured' ? 1 : 5;
            }
            if ($limitValue < 1 || $limitValue > 100) {
                $errors[] = 'Server list limit must be between 1 and 100.';
            }
            $settings['limit'] = $limitValue;

            $encoded = json_encode([
                'game' => $settings['game'] !== '' ? $settings['game'] : null,
                'limit' => $settings['limit'],
                'show_players' => $settings['show_players'],
                'show_join_button' => $settings['show_join_button'],
            ]);

            if ($encoded === false) {
                $errors[] = 'Server block settings could not be encoded.';
            } else {
                $content = $encoded;
            }
        } elseif ($content === '') {
            $errors[] = 'Content is required.';
        }

        return [
            'errors' => $errors,
            'type' => $type,
            'content' => $content,
            'settings' => $settings,
        ];
    }

    private function parseTemplatePayload(Request $request): array
    {
        $errors = [];
        $templateKey = trim((string) $request->request->get('template_key', ''));
        $applyTemplate = $request->request->get('apply_template') === 'on';

        if ($templateKey === '') {
            $errors[] = 'CMS template selection is required.';
        }

        return [
            'errors' => $errors,
            'template_key' => $templateKey,
            'apply_template' => $applyTemplate,
        ];
    }

    private function renderFormWithErrors(array $formData, int $statusCode, ?CmsPage $page = null): Response
    {
        return new Response($this->twig->render('admin/cms/pages/_form.html.twig', [
            'form' => $this->buildFormContext($page, [
                'title' => $formData['title'],
                'slug' => $formData['slug'],
                'is_published' => $formData['is_published'],
                'errors' => $formData['errors'],
            ]),
        ]), $statusCode);
    }

    private function renderBlockFormWithErrors(array $formData, int $statusCode): Response
    {
        $isEdit = ($formData['block_id'] ?? null) !== null;

        return new Response($this->twig->render('admin/cms/pages/_block_form.html.twig', [
            'blockForm' => $this->buildBlockFormContext(array_merge([
                'id' => $formData['block_id'] ?? null,
                'action' => $isEdit ? 'update' : 'create',
                'submit_label' => $isEdit ? 'update_block' : 'add_block',
                'submit_color' => $isEdit ? 'bg-amber-500 hover:bg-amber-600' : 'bg-indigo-600 hover:bg-indigo-700',
            ], $formData)),
            'page' => $formData['page'],
        ]), $statusCode);
    }

    private function renderTemplateFormWithErrors(array $formData, int $statusCode): Response
    {
        return new Response($this->twig->render('admin/cms/pages/_template_form.html.twig', [
            'templateForm' => $this->buildTemplateFormContext($formData['template_key'] ?? null, $formData),
            'templates' => $this->cmsTemplateCatalog->listTemplates(),
        ]), $statusCode);
    }

    /**
     * @param array<string, mixed> $template
     */
    private function applyTemplate(\App\Entity\Site $site, array $template): void
    {
        $existingPages = $this->pageRepository->findBy(['site' => $site]);
        $existingSlugs = array_map(static fn (CmsPage $page) => $page->getSlug(), $existingPages);

        foreach ($template['pages'] as $pageData) {
            if (in_array($pageData['slug'], $existingSlugs, true)) {
                continue;
            }

            $page = new CmsPage($site, $pageData['title'], $pageData['slug'], (bool) $pageData['is_published']);
            $this->entityManager->persist($page);

            $sortOrder = 1;
            foreach ($pageData['blocks'] as $blockData) {
                $block = new CmsBlock($page, $blockData['type'], $blockData['content'], $sortOrder);
                $page->addBlock($block);
                $this->entityManager->persist($block);
                $sortOrder++;
            }
        }
    }

    private function buildServerBlockPreview(string $content): string
    {
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return 'Server block (invalid settings)';
        }

        $game = is_string($data['game'] ?? null) && $data['game'] !== '' ? $data['game'] : 'all games';
        $limit = is_numeric($data['limit'] ?? null) ? (int) $data['limit'] : 0;
        $players = ($data['show_players'] ?? false) ? 'players shown' : 'players hidden';
        $join = ($data['show_join_button'] ?? false) ? 'join enabled' : 'join hidden';

        return sprintf('Server list: %s · limit %d · %s · %s', $game, $limit, $players, $join);
    }

    private function decodeBlockSettings(string $content): array
    {
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [
                'game' => '',
                'limit' => 5,
                'show_players' => true,
                'show_join_button' => false,
            ];
        }

        return [
            'game' => is_string($data['game'] ?? null) ? $data['game'] : '',
            'limit' => is_numeric($data['limit'] ?? null) ? (int) $data['limit'] : 5,
            'show_players' => (bool) ($data['show_players'] ?? true),
            'show_join_button' => (bool) ($data['show_join_button'] ?? false),
        ];
    }

    private function isDuplicateSlug(\App\Entity\Site $site, string $slug, ?CmsPage $existingPage): bool
    {
        $existing = $this->pageRepository->findOneBy([
            'site' => $site,
            'slug' => $slug,
        ]);

        if (!$existing instanceof CmsPage) {
            return false;
        }

        if ($existingPage === null) {
            return true;
        }

        return $existing->getId() !== $existingPage->getId();
    }
}
