<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Admin;

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
    ) {
    }

    #[Route(path: '', name: 'admin_cms_pages', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isCmsUser($request)) {
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
            'activeNav' => 'cms-pages',
        ]));
    }

    #[Route(path: '/table', name: 'admin_cms_pages_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->isCmsUser($request)) {
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
        if (!$this->isCmsUser($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/cms/pages/_form.html.twig', [
            'form' => $this->buildFormContext(),
        ]));
    }

    #[Route(path: '/{id}/edit', name: 'admin_cms_pages_edit', methods: ['GET'])]
    public function edit(Request $request, int $id): Response
    {
        if (!$this->isCmsUser($request)) {
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
        if (!$actor instanceof User || !$actor->isAdmin()) {
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

    #[Route(path: '/{id}', name: 'admin_cms_pages_update', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function update(Request $request, string $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $pageId = filter_var($id, FILTER_VALIDATE_INT);
        if ($pageId === false) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $page = $this->pageRepository->find($pageId);
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
        if (!$actor instanceof User || !$actor->isAdmin()) {
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

    #[Route(path: '/{id}/blocks/{blockId}', name: 'admin_cms_pages_blocks_update', methods: ['POST'])]
    public function updateBlock(Request $request, int $id, int $blockId): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
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
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
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
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->isAdmin();
    }

    private function moveBlock(Request $request, int $id, int $blockId, int $direction): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
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
        $currentIndex = null;
        foreach ($blocks as $index => $candidate) {
            if ($candidate->getId() === $blockId) {
                $currentIndex = $index;
                break;
            }
        }

        if ($currentIndex === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
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
            if ($block->getVersion() === 2 && is_array($block->getPayloadJson())) {
                $preview = json_encode($block->getPayloadJson(), JSON_UNESCAPED_SLASHES) ?: 'V2 payload';
            }
            if (in_array($block->getType(), ['server_list', 'server_featured'], true)) {
                $preview = $this->buildServerBlockPreview($block->getContent());
            }

            return [
                'id' => $block->getId(),
                'type' => $block->getType(),
                'content' => $block->getContent(),
                'version' => $block->getVersion(),
                'payload_json' => $block->getPayloadJson(),
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
            'version' => $block->getVersion(),
            'content' => $content,
            'payload' => $this->mergeV2Payload($block->getType(), $block->getPayloadJson()),
            'settings' => $settings,
            'action' => 'update',
            'submit_label' => 'update_block',
            'submit_color' => 'bg-amber-500 hover:bg-amber-600',
        ]);
    }

    /**
     * @param array<string, mixed>|null $overrides
     * @return array<string, mixed>
     */
    private function buildMaintenanceFormContext(
        \App\Module\Core\Domain\Entity\Site $site,
        ?array $overrides = null,
    ): array {
        $context = [
            'errors' => [],
            'enabled' => $site->isMaintenanceEnabled(),
            'message' => $site->getMaintenanceMessage(),
            'graphic_path' => $site->getMaintenanceGraphicPath(),
            'allowlist' => $site->getMaintenanceAllowlist(),
            'starts_at' => $this->formatDateTime($site->getMaintenanceStartsAt()),
            'ends_at' => $this->formatDateTime($site->getMaintenanceEndsAt()),
        ];

        if ($overrides !== null) {
            $context = array_merge($context, $overrides);
        }

        return $context;
    }

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
            $decoded = json_decode($cardsRaw, true);
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

    private function mergeV2Payload(string $type, ?array $payload): array
    {
        return array_replace_recursive($this->defaultV2Payload($type), $payload ?? []);
    }

    private function parseMaintenancePayload(Request $request): array
    {
        $errors = [];
        $enabled = $request->request->get('maintenance_enabled') === 'on';
        $message = trim((string) $request->request->get('maintenance_message', ''));
        $graphicPath = trim((string) $request->request->get('maintenance_graphic_path', ''));
        $allowlistRaw = trim((string) $request->request->get('maintenance_allowlist', ''));
        $startsRaw = trim((string) $request->request->get('maintenance_starts_at', ''));
        $endsRaw = trim((string) $request->request->get('maintenance_ends_at', ''));

        if (!$this->isValidMaintenanceGraphicPath($graphicPath)) {
            $errors[] = 'Maintenance graphic must be an absolute path (/...) or a valid URL.';
        }

        $startsAt = $this->parseDateTimeInput($startsRaw, 'Start time is invalid.', $errors);
        $endsAt = $this->parseDateTimeInput($endsRaw, 'End time is invalid.', $errors);

        if ($startsAt !== null && $endsAt !== null && $endsAt < $startsAt) {
            $errors[] = 'End time must be after start time.';
        }

        $allowlist = $this->normalizeAllowlist($allowlistRaw);
        foreach ($allowlist as $entry) {
            if (!$this->isValidIpOrCidr($entry)) {
                $errors[] = sprintf('Allowlist entry "%s" is invalid.', $entry);
            }
        }

        return [
            'errors' => $errors,
            'enabled' => $enabled,
            'message' => $message,
            'graphic_path' => $graphicPath,
            'allowlist' => implode("\n", $allowlist),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
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

    private function renderMaintenanceFormWithErrors(\App\Module\Core\Domain\Entity\Site $site, array $formData, int $statusCode): Response
    {
        return new Response($this->twig->render('admin/cms/pages/_maintenance_form.html.twig', [
            'maintenanceForm' => $this->buildMaintenanceFormContext($site, [
                'errors' => $formData['errors'],
                'enabled' => $formData['enabled'],
                'message' => $formData['message'],
                'graphic_path' => $formData['graphic_path'],
                'allowlist' => $formData['allowlist'],
                'starts_at' => $this->formatDateTime($formData['starts_at']),
                'ends_at' => $this->formatDateTime($formData['ends_at']),
            ]),
        ]), $statusCode);
    }

    private function isValidMaintenanceGraphicPath(string $path): bool
    {
        if ($path === '') {
            return true;
        }

        if (str_starts_with($path, '/')) {
            return true;
        }

        return filter_var($path, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * @param array<string, mixed> $template
     */
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

    /**
     * @param array<int, string> $errors
     */
    private function parseDateTimeInput(string $raw, string $errorMessage, array &$errors): ?\DateTimeImmutable
    {
        if ($raw === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $raw) ?: null;
        if ($parsed === null) {
            $errors[] = $errorMessage;
        }

        return $parsed;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeAllowlist(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\n,]+/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $entry): bool => $entry !== ''));
    }

    private function isValidIpOrCidr(string $entry): bool
    {
        if (filter_var($entry, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (!str_contains($entry, '/')) {
            return false;
        }

        [$ip, $mask] = array_pad(explode('/', $entry, 2), 2, null);
        if ($ip === null || $mask === null) {
            return false;
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (!is_numeric($mask)) {
            return false;
        }

        $maskInt = (int) $mask;
        $maxMask = str_contains($ip, ':') ? 128 : 32;
        if ($maskInt < 0 || $maskInt > $maxMask) {
            return false;
        }

        return IpUtils::checkIp($ip, $entry);
    }

    private function formatDateTime(?\DateTimeImmutable $dateTime): string
    {
        return $dateTime?->format('Y-m-d\TH:i') ?? '';
    }
}
