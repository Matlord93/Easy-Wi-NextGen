<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\ChangelogEntry;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\ChangelogEntryRepository;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\SiteResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/changelog')]
final class AdminChangelogController
{
    public function __construct(
        private readonly ChangelogEntryRepository $changelogRepository,
        private readonly SiteResolver $siteResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_changelog', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $entries = $this->changelogRepository->findBy(['siteId' => $site->getId()], ['publishedAt' => 'DESC']);

        return new Response($this->twig->render('admin/changelog/index.html.twig', [
            'entries' => $this->normalizeEntries($entries),
            'summary' => $this->buildSummary($entries),
            'form' => $this->buildFormContext(),
            'activeNav' => 'changelog',
        ]));
    }

    #[Route(path: '/table', name: 'admin_changelog_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $entries = $this->changelogRepository->findBy(['siteId' => $site->getId()], ['publishedAt' => 'DESC']);

        return new Response($this->twig->render('admin/changelog/_table.html.twig', [
            'entries' => $this->normalizeEntries($entries),
        ]));
    }

    #[Route(path: '/form', name: 'admin_changelog_form', methods: ['GET'])]
    public function form(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/changelog/_form.html.twig', [
            'form' => $this->buildFormContext(),
        ]));
    }

    #[Route(path: '/{id}/edit', name: 'admin_changelog_edit', methods: ['GET'])]
    public function edit(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $entry = $this->changelogRepository->find($id);
        if ($entry === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $entry->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('admin/changelog/_form.html.twig', [
            'form' => $this->buildFormContext($entry),
        ]));
    }

    #[Route(path: '', name: 'admin_changelog_create', methods: ['POST'])]
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

        $formData = $this->parsePayload($request);
        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST);
        }

        $entry = new ChangelogEntry(
            siteId: $site->getId() ?? 0,
            title: $formData['title'],
            content: $formData['content'],
            publishedAt: $formData['published_at'],
            version: $formData['version'],
            visiblePublic: $formData['visible_public'],
        );

        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'changelog_entry.created', [
            'entry_id' => $entry->getId(),
            'site_id' => $entry->getSiteId(),
            'title' => $entry->getTitle(),
            'version' => $entry->getVersion(),
            'visible_public' => $entry->isVisiblePublic(),
        ]);
        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/changelog/_form.html.twig', [
            'form' => $this->buildFormContext(),
        ]));
        $response->headers->set('HX-Trigger', 'changelog-changed');

        return $response;
    }

    #[Route(path: '/{id}', name: 'admin_changelog_update', methods: ['POST'])]
    public function update(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $entry = $this->changelogRepository->find($id);
        if ($entry === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $entry->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $formData = $this->parsePayload($request);
        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST, $entry);
        }

        $previous = [
            'title' => $entry->getTitle(),
            'version' => $entry->getVersion(),
            'published_at' => $entry->getPublishedAt()->format(DATE_RFC3339),
            'visible_public' => $entry->isVisiblePublic(),
        ];

        $entry->setTitle($formData['title']);
        $entry->setVersion($formData['version']);
        $entry->setContent($formData['content']);
        $entry->setPublishedAt($formData['published_at']);
        $entry->setVisiblePublic($formData['visible_public']);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'changelog_entry.updated', [
            'entry_id' => $entry->getId(),
            'site_id' => $entry->getSiteId(),
            'previous' => $previous,
            'current' => [
                'title' => $entry->getTitle(),
                'version' => $entry->getVersion(),
                'published_at' => $entry->getPublishedAt()->format(DATE_RFC3339),
                'visible_public' => $entry->isVisiblePublic(),
            ],
        ]);
        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/changelog/_form.html.twig', [
            'form' => $this->buildFormContext(),
        ]));
        $response->headers->set('HX-Trigger', 'changelog-changed');

        return $response;
    }

    #[Route(path: '/{id}/delete', name: 'admin_changelog_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $entry = $this->changelogRepository->find($id);
        if ($entry === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $entry->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $this->auditLogger->log($actor, 'changelog_entry.deleted', [
            'entry_id' => $entry->getId(),
            'site_id' => $entry->getSiteId(),
            'title' => $entry->getTitle(),
        ]);

        $this->entityManager->remove($entry);
        $this->entityManager->flush();

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->headers->set('HX-Trigger', 'changelog-changed');

        return $response;
    }

    private function parsePayload(Request $request): array
    {
        $errors = [];

        $title = trim((string) $request->request->get('title', ''));
        $version = trim((string) $request->request->get('version', ''));
        $content = trim((string) $request->request->get('content', ''));
        $publishedAt = $this->toDateTime($request->request->get('published_at'));
        $visiblePublic = $request->request->get('visible_public') === 'on';

        if ($title === '') {
            $errors[] = 'Title is required.';
        }
        if ($content === '') {
            $errors[] = 'Content is required.';
        }
        if ($publishedAt === null) {
            $errors[] = 'Publish date is required.';
        }

        return [
            'errors' => $errors,
            'title' => $title,
            'version' => $version !== '' ? $version : null,
            'content' => $content,
            'published_at' => $publishedAt ?? new \DateTimeImmutable(),
            'visible_public' => $visiblePublic,
        ];
    }

    private function buildFormContext(?ChangelogEntry $entry = null, ?array $override = null): array
    {
        $data = [
            'id' => $entry?->getId(),
            'title' => $entry?->getTitle() ?? '',
            'version' => $entry?->getVersion() ?? '',
            'content' => $entry?->getContent() ?? '',
            'published_at' => $entry?->getPublishedAt()->format('Y-m-d') ?? (new \DateTimeImmutable())->format('Y-m-d'),
            'visible_public' => $entry?->isVisiblePublic() ?? false,
            'errors' => [],
            'action' => $entry === null ? 'create' : 'update',
            'submit_label' => $entry === null ? 'Create Entry' : 'Update Entry',
            'submit_color' => $entry === null ? 'bg-indigo-600' : 'bg-amber-500',
            'action_url' => $entry === null ? '/admin/changelog' : sprintf('/admin/changelog/%d', $entry->getId()),
        ];

        if ($override !== null) {
            $data = array_merge($data, $override);
        }

        return $data;
    }

    private function renderFormWithErrors(array $formData, int $status, ?ChangelogEntry $entry = null): Response
    {
        $formContext = $this->buildFormContext($entry, [
            'title' => $formData['title'],
            'version' => $formData['version'] ?? '',
            'content' => $formData['content'],
            'published_at' => $formData['published_at']->format('Y-m-d'),
            'visible_public' => $formData['visible_public'],
            'errors' => $formData['errors'],
        ]);

        return new Response($this->twig->render('admin/changelog/_form.html.twig', [
            'form' => $formContext,
        ]), $status);
    }

    /**
     * @param ChangelogEntry[] $entries
     */
    private function buildSummary(array $entries): array
    {
        $summary = [
            'total' => count($entries),
            'public' => 0,
            'hidden' => 0,
        ];

        foreach ($entries as $entry) {
            if ($entry->isVisiblePublic()) {
                $summary['public']++;
            } else {
                $summary['hidden']++;
            }
        }

        return $summary;
    }

    /**
     * @param ChangelogEntry[] $entries
     */
    private function normalizeEntries(array $entries): array
    {
        return array_map(static function (ChangelogEntry $entry): array {
            return [
                'id' => $entry->getId(),
                'title' => $entry->getTitle(),
                'version' => $entry->getVersion(),
                'content' => $entry->getContent(),
                'published_at' => $entry->getPublishedAt(),
                'visible_public' => $entry->isVisiblePublic(),
            ];
        }, $entries);
    }

    private function toDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function isAdmin(Request $request): bool
    {
        $user = $request->attributes->get('current_user');

        return $user instanceof User && $user->isAdmin();
    }
}
