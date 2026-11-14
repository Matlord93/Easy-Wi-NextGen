<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Admin;

use App\Infrastructure\Config\JsonArray;

use App\Infrastructure\Http\RequestActor;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\CmsBlock;
use App\Module\Core\Domain\Entity\CmsPage;
use App\Module\Core\Domain\Entity\User;
use App\Repository\CmsBlockRepository;
use App\Repository\CmsPageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
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
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '', name: 'admin_cms_pages', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isCmsUser($request)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response($this->translator->trans('error_site_not_found'), Response::HTTP_NOT_FOUND);
        }

        $pages = $this->pageRepository->findBy(['site' => $site], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/cms/pages/index.html.twig', [
            'pages' => $this->normalizePages($pages),
            'summary' => $this->buildSummary($pages),
            'form' => $this->buildFormContext(),
            'activeNav' => 'cms-pages',
        ]));
    }

    #[Route(path: '/table', name: 'admin_cms_pages_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->isCmsUser($request)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response($this->translator->trans('error_site_not_found'), Response::HTTP_NOT_FOUND);
        }

        $pages = $this->pageRepository->findBy(['site' => $site], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/cms/pages/_table.html.twig', [
            'pages' => $this->normalizePages($pages),
        ]));
    }

    #[Route(path: '/form', name: 'admin_cms_pages_form', methods: ['GET'])]
    public function form(Request $request): Response
    {
        if (!$this->isCmsUser($request)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/cms/pages/_form.html.twig', [
            'form' => $this->buildFormContext(),
        ]));
    }

    #[Route(path: '/{id}/edit', name: 'admin_cms_pages_edit', methods: ['GET'])]
    public function edit(Request $request, int $id): Response
    {
        if (!$this->isCmsUser($request)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response($this->translator->trans('error_site_not_found'), Response::HTTP_NOT_FOUND);
        }

        $page = $this->pageRepository->find($id);
        if (!$page instanceof CmsPage || $page->getSite()->getId() !== $site->getId()) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('admin/cms/pages/_form.html.twig', [
            'form' => $this->buildFormContext($page),
        ]));
    }

    #[Route(path: '', name: 'admin_cms_pages_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $actor = RequestActor::user($request);
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response($this->translator->trans('error_site_not_found'), Response::HTTP_NOT_FOUND);
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

    #[Route(path: '/{id}', name: 'admin_cms_pages_update', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function update(Request $request, string $id): Response
    {
        $actor = RequestActor::user($request);
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $pageId = filter_var($id, FILTER_VALIDATE_INT);
        if ($pageId === false) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response($this->translator->trans('error_site_not_found'), Response::HTTP_NOT_FOUND);
        }

        $page = $this->pageRepository->find($pageId);
        if (!$page instanceof CmsPage || $page->getSite()->getId() !== $site->getId()) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
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
        $actor = RequestActor::user($request);
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response($this->translator->trans('error_site_not_found'), Response::HTTP_NOT_FOUND);
        }

        $page = $this->pageRepository->find($id);
        if (!$page instanceof CmsPage || $page->getSite()->getId() !== $site->getId()) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
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

    #[Route(path: '/{id}/blocks', name: 'admin_cms_pages_blocks_create', methods: ['POST'])]
    public function createBlock(Request $request, int $id): Response
    {
        $actor = RequestActor::user($request);
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $page = $this->pageRepository->find($id);
        if (!$page instanceof CmsPage) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }

        $formData = $this->parseBlockPayload($request);
        if ($formData['errors'] !== []) {
            $formData['page'] = $this->normalizePage($page);
            return $this->renderBlockFormWithErrors($formData, Response::HTTP_BAD_REQUEST);
        }

        $existingBlocks = $this->blockRepository->findBy(['page' => $page], ['sortOrder' => 'ASC']);
        $maxSortOrder = count($existingBlocks) > 0 ? max(array_map(static fn (CmsBlock $b): int => $b->getSortOrder(), $existingBlocks)) : 0;

        $block = new CmsBlock($page, $formData['type'], $formData['content'], $maxSortOrder + 1);
        $block->setVersion($formData['version']);
        $block->setPayloadJson($formData['version'] === 2 ? $formData['payload'] : null);
        $block->setSettingsJson($formData['version'] === 2 ? ['editor' => 'v2'] : null);
        $this->entityManager->persist($block);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'cms.block.created', [
            'page_id' => $page->getId(),
            'block_id' => $block->getId(),
            'type' => $block->getType(),
            'version' => $block->getVersion(),
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
        $actor = RequestActor::user($request);
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response($this->translator->trans('error_site_not_found'), Response::HTTP_NOT_FOUND);
        }

        $page = $this->pageRepository->find($id);
        if (!$page instanceof CmsPage || $page->getSite()->getId() !== $site->getId()) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }

        $block = $this->blockRepository->find($blockId);
        if (!$block instanceof CmsBlock || $block->getPage()->getId() !== $page->getId()) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
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
            'version' => $block->getVersion(),
            'payload_json' => $block->getPayloadJson(),
        ];

        $block->setType($formData['type']);
        $block->setContent($formData['content']);
        $block->setVersion($formData['version']);
        $block->setPayloadJson($formData['version'] === 2 ? $formData['payload'] : null);
        $block->setSettingsJson($formData['version'] === 2 ? ['editor' => 'v2'] : null);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'cms.block.updated', [
            'page_id' => $page->getId(),
            'site_id' => $page->getSite()->getId(),
            'block_id' => $block->getId(),
            'previous' => $previous,
            'current' => [
                'type' => $block->getType(),
                'content' => $block->getContent(),
                'version' => $block->getVersion(),
                'payload_json' => $block->getPayloadJson(),
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
        $actor = RequestActor::user($request);
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response($this->translator->trans('error_site_not_found'), Response::HTTP_NOT_FOUND);
        }

        $page = $this->pageRepository->find($id);
        if (!$page instanceof CmsPage || $page->getSite()->getId() !== $site->getId()) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }

        $block = $this->blockRepository->find($blockId);
        if (!$block instanceof CmsBlock || $block->getPage()->getId() !== $page->getId()) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
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

    #[Route(path: '/{id}/blocks/{blockId}/move-up', name: 'admin_cms_pages_blocks_move_up', methods: ['POST'])]
    public function moveBlockUp(Request $request, int $id, int $blockId): Response
    {
        return $this->moveBlock($request, $id, $blockId, -1);
    }

    #[Route(path: '/{id}/blocks/{blockId}/move-down', name: 'admin_cms_pages_blocks_move_down', methods: ['POST'])]
    public function moveBlockDown(Request $request, int $id, int $blockId): Response
    {
        return $this->moveBlock($request, $id, $blockId, 1);
    }

    private function isCmsUser(Request $request): bool
    {
        $actor = RequestActor::user($request);

        return $actor instanceof User && $actor->isAdmin();
    }

    private function moveBlock(Request $request, int $id, int $blockId, int $direction): Response
    {
        $actor = RequestActor::user($request);
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response($this->translator->trans('error_site_not_found'), Response::HTTP_NOT_FOUND);
        }

        $page = $this->pageRepository->find($id);
        if (!$page instanceof CmsPage || $page->getSite()->getId() !== $site->getId()) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }

        $blocks = $this->blockRepository->findBy(['page' => $page], ['sortOrder' => 'ASC']);
        $currentIndex = null;
        foreach ($blocks as $index => $candidate) {
            if ($candidate->getId() === $blockId) {
                $currentIndex = $index;
                break;
            }
        }

        if ($currentIndex === null) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }

        $targetIndex = $currentIndex + $direction;
        if (!isset($blocks[$targetIndex])) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $current = $blocks[$currentIndex];
        $target = $blocks[$targetIndex];
        $currentOrder = $current->getSortOrder();
        $current->setSortOrder($target->getSortOrder());
        $target->setSortOrder($currentOrder);
        $this->entityManager->flush();

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->headers->set('HX-Trigger', 'cms-blocks-changed');

        return $response;
    }

    /** @param list<CmsPage> $pages
     * @return array{total:int,published:int,blocks:int}
     */
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

    /** @param list<CmsPage> $pages
     * @return list<array<string,mixed>>
     */
    private function normalizePages(array $pages): array
    {
        return array_map(fn (CmsPage $page) => $this->normalizePage($page), $pages);
    }

    /**
     * @return array{id: int|null, title: string, slug: string, is_published: bool, block_count: int,
     * updated_at: \DateTimeImmutable}
     */
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
            'action_url' => $page === null ? '/admin/cms/pages' : sprintf('/admin/cms/pages/%d', $page->getId() ?? throw new \LogicException('Cannot edit an unpersisted CMS page.')),
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
            'version' => 1,
            'content' => '',
            'payload' => $this->defaultV2Payload(''),
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





    /** @return array{errors:list<string>,title:string,slug:string,is_published:bool} */
    private function parsePayload(Request $request, \App\Module\Core\Domain\Entity\Site $site, ?CmsPage $existingPage = null): array
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

    /**
     * @return array{errors:array<int,string>,type:string,version:int,content:string,payload:?array<string,mixed>,settings:array<string,scalar|null>}
     */
    private function parseBlockPayload(Request $request): array
    {
        $errors = [];
        $type = trim((string) $request->request->get('type', ''));
        $content = trim((string) $request->request->get('content', ''));
        $version = max(1, (int) $request->request->get('version', 1));

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

        $payload = null;

        if ($version === 2 && !$isServerBlock) {
            $payload = $this->parseV2Payload($type, $request, $errors);
        }

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
        } elseif ($version !== 2 && $content === '') {
            $errors[] = 'Content is required.';
        }

        return [
            'errors' => $errors,
            'type' => $type,
            'version' => $version,
            'content' => $content,
            'payload' => $payload,
            'settings' => $settings,
        ];
    }

    /**
     * @param array<int, string> $errors
     * @return array<string, mixed>
     */
    private function parseV2Payload(string $type, Request $request, array &$errors): array
    {
        if ($type === 'hero') {
            return [
                'headline' => trim((string) $request->request->get('hero_headline', '')),
                'subheadline' => trim((string) $request->request->get('hero_subheadline', '')),
                'backgroundImagePath' => trim((string) $request->request->get('hero_background_image_path', '')),
                'ctaText' => trim((string) $request->request->get('hero_cta_text', '')),
                'ctaUrl' => trim((string) $request->request->get('hero_cta_url', '')),
            ];
        }

        if ($type === 'rich_text') {
            $content = trim((string) $request->request->get('rich_text_content', ''));
            if ($content === '') {
                $errors[] = 'Rich text content is required.';
            }

            return ['content' => $content];
        }

        if ($type === 'image') {
            $path = trim((string) $request->request->get('image_path', ''));
            if ($path === '') {
                $errors[] = 'Image path is required.';
            }

            return [
                'path' => $path,
                'alt' => trim((string) $request->request->get('image_alt', '')),
                'caption' => trim((string) $request->request->get('image_caption', '')),
            ];
        }

        if ($type === 'cards') {
            $cardsRaw = trim((string) $request->request->get('cards_json', '[]'));
            $decoded = JsonArray::decode($cardsRaw);
            if (!is_array($decoded)) {
                $errors[] = 'Cards JSON must be a valid JSON array.';
                $decoded = [];
            }

            return ['cards' => $decoded];
        }

        $errors[] = 'Unsupported V2 block type.';

        return [];
    }

    private function defaultV2Payload(string $type): array
    {
        return match ($type) {
            'hero' => ['headline' => '', 'subheadline' => '', 'backgroundImagePath' => '', 'ctaText' => '', 'ctaUrl' => ''],
            'rich_text' => ['content' => ''],
            'image' => ['path' => '', 'alt' => '', 'caption' => ''],
            'cards' => ['cards' => []],
            default => [],
        };
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









    private function isDuplicateSlug(\App\Module\Core\Domain\Entity\Site $site, string $slug, ?CmsPage $existingPage): bool
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
