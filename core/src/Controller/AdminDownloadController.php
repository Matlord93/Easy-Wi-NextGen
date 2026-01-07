<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DownloadItem;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\DownloadItemRepository;
use App\Service\AuditLogger;
use App\Service\SiteResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/downloads')]
final class AdminDownloadController
{
    public function __construct(
        private readonly DownloadItemRepository $downloadRepository,
        private readonly SiteResolver $siteResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_downloads', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $items = $this->downloadRepository->findBy(['siteId' => $site->getId()], ['sortOrder' => 'ASC']);

        return new Response($this->twig->render('admin/downloads/index.html.twig', [
            'items' => $this->normalizeItems($items),
            'summary' => $this->buildSummary($items),
            'form' => $this->buildFormContext(),
            'activeNav' => 'downloads',
        ]));
    }

    #[Route(path: '/table', name: 'admin_downloads_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $items = $this->downloadRepository->findBy(['siteId' => $site->getId()], ['sortOrder' => 'ASC']);

        return new Response($this->twig->render('admin/downloads/_table.html.twig', [
            'items' => $this->normalizeItems($items),
        ]));
    }

    #[Route(path: '/form', name: 'admin_downloads_form', methods: ['GET'])]
    public function form(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/downloads/_form.html.twig', [
            'form' => $this->buildFormContext(),
        ]));
    }

    #[Route(path: '/{id}/edit', name: 'admin_downloads_edit', methods: ['GET'])]
    public function edit(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $item = $this->downloadRepository->find($id);
        if ($item === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $item->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('admin/downloads/_form.html.twig', [
            'form' => $this->buildFormContext($item),
        ]));
    }

    #[Route(path: '', name: 'admin_downloads_create', methods: ['POST'])]
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

        $formData = $this->parsePayload($request);
        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST);
        }

        $item = new DownloadItem(
            siteId: $site->getId() ?? 0,
            title: $formData['title'],
            url: $formData['url'],
            description: $formData['description'],
            version: $formData['version'],
            fileSize: $formData['file_size'],
            visiblePublic: $formData['visible_public'],
            sortOrder: $formData['sort_order'],
        );

        $this->entityManager->persist($item);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'download_item.created', [
            'download_id' => $item->getId(),
            'site_id' => $item->getSiteId(),
            'title' => $item->getTitle(),
            'url' => $item->getUrl(),
            'visible_public' => $item->isVisiblePublic(),
        ]);
        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/downloads/_form.html.twig', [
            'form' => $this->buildFormContext(),
        ]));
        $response->headers->set('HX-Trigger', 'downloads-changed');

        return $response;
    }

    #[Route(path: '/{id}', name: 'admin_downloads_update', methods: ['POST'])]
    public function update(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $item = $this->downloadRepository->find($id);
        if ($item === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $item->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $formData = $this->parsePayload($request);
        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST, $item);
        }

        $previous = [
            'title' => $item->getTitle(),
            'url' => $item->getUrl(),
            'version' => $item->getVersion(),
            'file_size' => $item->getFileSize(),
            'visible_public' => $item->isVisiblePublic(),
            'sort_order' => $item->getSortOrder(),
        ];

        $item->setTitle($formData['title']);
        $item->setDescription($formData['description']);
        $item->setUrl($formData['url']);
        $item->setVersion($formData['version']);
        $item->setFileSize($formData['file_size']);
        $item->setVisiblePublic($formData['visible_public']);
        $item->setSortOrder($formData['sort_order']);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'download_item.updated', [
            'download_id' => $item->getId(),
            'site_id' => $item->getSiteId(),
            'previous' => $previous,
            'current' => [
                'title' => $item->getTitle(),
                'url' => $item->getUrl(),
                'version' => $item->getVersion(),
                'file_size' => $item->getFileSize(),
                'visible_public' => $item->isVisiblePublic(),
                'sort_order' => $item->getSortOrder(),
            ],
        ]);
        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/downloads/_form.html.twig', [
            'form' => $this->buildFormContext(),
        ]));
        $response->headers->set('HX-Trigger', 'downloads-changed');

        return $response;
    }

    #[Route(path: '/{id}/delete', name: 'admin_downloads_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $item = $this->downloadRepository->find($id);
        if ($item === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $item->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $this->auditLogger->log($actor, 'download_item.deleted', [
            'download_id' => $item->getId(),
            'site_id' => $item->getSiteId(),
            'title' => $item->getTitle(),
        ]);

        $this->entityManager->remove($item);
        $this->entityManager->flush();

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->headers->set('HX-Trigger', 'downloads-changed');

        return $response;
    }

    private function parsePayload(Request $request): array
    {
        $errors = [];

        $title = trim((string) $request->request->get('title', ''));
        $description = trim((string) $request->request->get('description', ''));
        $url = trim((string) $request->request->get('url', ''));
        $version = trim((string) $request->request->get('version', ''));
        $fileSize = trim((string) $request->request->get('file_size', ''));
        $visiblePublic = $request->request->get('visible_public') === 'on';
        $sortOrder = (int) $request->request->get('sort_order', 0);

        if ($title === '') {
            $errors[] = 'Title is required.';
        }
        if ($url === '') {
            $errors[] = 'URL is required.';
        } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[] = 'URL must be valid.';
        }

        return [
            'errors' => $errors,
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'url' => $url,
            'version' => $version !== '' ? $version : null,
            'file_size' => $fileSize !== '' ? $fileSize : null,
            'visible_public' => $visiblePublic,
            'sort_order' => $sortOrder,
        ];
    }

    private function buildFormContext(?DownloadItem $item = null, ?array $override = null): array
    {
        $data = [
            'id' => $item?->getId(),
            'title' => $item?->getTitle() ?? '',
            'description' => $item?->getDescription() ?? '',
            'url' => $item?->getUrl() ?? '',
            'version' => $item?->getVersion() ?? '',
            'file_size' => $item?->getFileSize() ?? '',
            'visible_public' => $item?->isVisiblePublic() ?? false,
            'sort_order' => $item?->getSortOrder() ?? 0,
            'errors' => [],
            'action' => $item === null ? 'create' : 'update',
            'submit_label' => $item === null ? 'Create Download' : 'Update Download',
            'submit_color' => $item === null ? 'bg-indigo-600' : 'bg-amber-500',
            'action_url' => $item === null ? '/admin/downloads' : sprintf('/admin/downloads/%d', $item->getId()),
        ];

        if ($override !== null) {
            $data = array_merge($data, $override);
        }

        return $data;
    }

    private function renderFormWithErrors(array $formData, int $status, ?DownloadItem $item = null): Response
    {
        $formContext = $this->buildFormContext($item, [
            'title' => $formData['title'],
            'description' => $formData['description'] ?? '',
            'url' => $formData['url'],
            'version' => $formData['version'] ?? '',
            'file_size' => $formData['file_size'] ?? '',
            'visible_public' => $formData['visible_public'],
            'sort_order' => $formData['sort_order'],
            'errors' => $formData['errors'],
        ]);

        return new Response($this->twig->render('admin/downloads/_form.html.twig', [
            'form' => $formContext,
        ]), $status);
    }

    /**
     * @param DownloadItem[] $items
     */
    private function buildSummary(array $items): array
    {
        $summary = [
            'total' => count($items),
            'public' => 0,
            'hidden' => 0,
        ];

        foreach ($items as $item) {
            if ($item->isVisiblePublic()) {
                $summary['public']++;
            } else {
                $summary['hidden']++;
            }
        }

        return $summary;
    }

    /**
     * @param DownloadItem[] $items
     */
    private function normalizeItems(array $items): array
    {
        return array_map(static function (DownloadItem $item): array {
            return [
                'id' => $item->getId(),
                'title' => $item->getTitle(),
                'description' => $item->getDescription(),
                'url' => $item->getUrl(),
                'version' => $item->getVersion(),
                'file_size' => $item->getFileSize(),
                'sort_order' => $item->getSortOrder(),
                'visible_public' => $item->isVisiblePublic(),
                'updated_at' => $item->getUpdatedAt(),
            ];
        }, $items);
    }

    private function isAdmin(Request $request): bool
    {
        $user = $request->attributes->get('current_user');

        return $user instanceof User && $user->getType() === UserType::Admin;
    }
}
