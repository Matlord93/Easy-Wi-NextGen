<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CmsBlock;
use App\Entity\CmsPage;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\CmsBlockRepository;
use App\Repository\CmsPageRepository;
use App\Service\AuditLogger;
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
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_cms_pages', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $pages = $this->pageRepository->findBy([], ['updatedAt' => 'DESC']);

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
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $pages = $this->pageRepository->findBy([], ['updatedAt' => 'DESC']);

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

    #[Route(path: '', name: 'admin_cms_pages_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $formData = $this->parsePayload($request);

        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST);
        }

        $page = new CmsPage($formData['title'], $formData['slug'], $formData['is_published']);
        $this->entityManager->persist($page);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'cms.page.created', [
            'page_id' => $page->getId(),
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

    #[Route(path: '/{id}', name: 'admin_cms_pages_show', methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $page = $this->pageRepository->find($id);
        if (!$page instanceof CmsPage) {
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

        $page = $this->pageRepository->find($id);
        if (!$page instanceof CmsPage) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $blocks = $this->blockRepository->findBy(['page' => $page], ['sortOrder' => 'ASC']);

        return new Response($this->twig->render('admin/cms/pages/_blocks_table.html.twig', [
            'blocks' => $this->normalizeBlocks($blocks),
        ]));
    }

    #[Route(path: '/{id}/blocks/form', name: 'admin_cms_pages_blocks_form', methods: ['GET'])]
    public function blockForm(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $page = $this->pageRepository->find($id);
        if (!$page instanceof CmsPage) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('admin/cms/pages/_block_form.html.twig', [
            'blockForm' => $this->buildBlockFormContext(),
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

        $page = $this->pageRepository->find($id);
        if (!$page instanceof CmsPage) {
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

    private function buildFormContext(?array $overrides = null): array
    {
        $defaults = [
            'errors' => [],
            'title' => '',
            'slug' => '',
            'is_published' => false,
        ];

        return array_merge($defaults, $overrides ?? []);
    }

    private function buildBlockFormContext(?array $overrides = null): array
    {
        $defaults = [
            'errors' => [],
            'type' => '',
            'content' => '',
            'settings' => [
                'game' => '',
                'limit' => 5,
                'show_players' => true,
                'show_join_button' => false,
            ],
        ];

        return array_merge($defaults, $overrides ?? []);
    }

    private function parsePayload(Request $request): array
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

    private function renderFormWithErrors(array $formData, int $statusCode): Response
    {
        return new Response($this->twig->render('admin/cms/pages/_form.html.twig', [
            'form' => $this->buildFormContext($formData),
        ]), $statusCode);
    }

    private function renderBlockFormWithErrors(array $formData, int $statusCode): Response
    {
        return new Response($this->twig->render('admin/cms/pages/_block_form.html.twig', [
            'blockForm' => $this->buildBlockFormContext($formData),
            'page' => $formData['page'],
        ]), $statusCode);
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
}
