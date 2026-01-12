<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\MaintenanceWindow;
use App\Entity\StatusComponent;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\MaintenanceWindowRepository;
use App\Repository\StatusComponentRepository;
use App\Service\AuditLogger;
use App\Service\SiteResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/status/maintenance')]
final class AdminMaintenanceWindowController
{
    public function __construct(
        private readonly MaintenanceWindowRepository $windowRepository,
        private readonly StatusComponentRepository $componentRepository,
        private readonly SiteResolver $siteResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_maintenance_windows', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $windows = $this->windowRepository->findBy(['siteId' => $site->getId()], ['startAt' => 'DESC']);
        $components = $this->componentRepository->findBy(['siteId' => $site->getId()], ['name' => 'ASC']);

        return new Response($this->twig->render('admin/status/maintenance/index.html.twig', [
            'windows' => $this->normalizeWindows($windows),
            'summary' => $this->buildSummary($windows),
            'form' => $this->buildFormContext(),
            'components' => $this->normalizeComponents($components),
            'activeNav' => 'status-maintenance',
        ]));
    }

    #[Route(path: '/table', name: 'admin_maintenance_windows_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $windows = $this->windowRepository->findBy(['siteId' => $site->getId()], ['startAt' => 'DESC']);

        return new Response($this->twig->render('admin/status/maintenance/_table.html.twig', [
            'windows' => $this->normalizeWindows($windows),
        ]));
    }

    #[Route(path: '/form', name: 'admin_maintenance_windows_form', methods: ['GET'])]
    public function form(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $components = $this->componentRepository->findBy(['siteId' => $site->getId()], ['name' => 'ASC']);

        return new Response($this->twig->render('admin/status/maintenance/_form.html.twig', [
            'form' => $this->buildFormContext(),
            'components' => $this->normalizeComponents($components),
        ]));
    }

    #[Route(path: '/{id}/edit', name: 'admin_maintenance_windows_edit', methods: ['GET'])]
    public function edit(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $window = $this->windowRepository->find($id);
        if ($window === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $window->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $components = $this->componentRepository->findBy(['siteId' => $site->getId()], ['name' => 'ASC']);

        return new Response($this->twig->render('admin/status/maintenance/_form.html.twig', [
            'form' => $this->buildFormContext($window),
            'components' => $this->normalizeComponents($components),
        ]));
    }

    #[Route(path: '', name: 'admin_maintenance_windows_create', methods: ['POST'])]
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
            return $this->renderFormWithErrors($request, $formData, Response::HTTP_BAD_REQUEST);
        }

        $window = new MaintenanceWindow(
            siteId: $site->getId() ?? 0,
            title: $formData['title'],
            startAt: $formData['start_at'],
            endAt: $formData['end_at'],
            message: $formData['message'],
            visiblePublic: $formData['visible_public'],
        );

        $components = $this->resolveComponents($site->getId() ?? 0, $formData['affected_component_ids']);
        $window->syncAffectedComponents($components);

        $this->entityManager->persist($window);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'maintenance_window.created', [
            'window_id' => $window->getId(),
            'site_id' => $window->getSiteId(),
            'title' => $window->getTitle(),
            'start_at' => $window->getStartAt()->format(DATE_RFC3339),
            'end_at' => $window->getEndAt()->format(DATE_RFC3339),
            'visible_public' => $window->isVisiblePublic(),
            'affected_component_ids' => array_map(static fn (StatusComponent $component): int => $component->getId() ?? 0, $components),
        ]);
        $this->entityManager->flush();

        $componentsForForm = $this->componentRepository->findBy(['siteId' => $site->getId()], ['name' => 'ASC']);

        $response = new Response($this->twig->render('admin/status/maintenance/_form.html.twig', [
            'form' => $this->buildFormContext(),
            'components' => $this->normalizeComponents($componentsForForm),
        ]));
        $response->headers->set('HX-Trigger', 'maintenance-windows-changed');

        return $response;
    }

    #[Route(path: '/{id}', name: 'admin_maintenance_windows_update', methods: ['POST'])]
    public function update(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $window = $this->windowRepository->find($id);
        if ($window === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $window->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $formData = $this->parsePayload($request);
        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($request, $formData, Response::HTTP_BAD_REQUEST, $window);
        }

        $previous = [
            'title' => $window->getTitle(),
            'start_at' => $window->getStartAt()->format(DATE_RFC3339),
            'end_at' => $window->getEndAt()->format(DATE_RFC3339),
            'visible_public' => $window->isVisiblePublic(),
        ];

        $window->setTitle($formData['title']);
        $window->setStartAt($formData['start_at']);
        $window->setEndAt($formData['end_at']);
        $window->setMessage($formData['message']);
        $window->setVisiblePublic($formData['visible_public']);

        $components = $this->resolveComponents($site->getId() ?? 0, $formData['affected_component_ids']);
        $window->syncAffectedComponents($components);

        $this->auditLogger->log($actor, 'maintenance_window.updated', [
            'window_id' => $window->getId(),
            'site_id' => $window->getSiteId(),
            'title' => $window->getTitle(),
            'visible_public' => $window->isVisiblePublic(),
            'affected_component_ids' => array_map(static fn (StatusComponent $component): int => $component->getId() ?? 0, $components),
            'previous' => $previous,
        ]);

        $this->entityManager->flush();

        $componentsForForm = $this->componentRepository->findBy(['siteId' => $site->getId()], ['name' => 'ASC']);

        $response = new Response($this->twig->render('admin/status/maintenance/_form.html.twig', [
            'form' => $this->buildFormContext(),
            'components' => $this->normalizeComponents($componentsForForm),
        ]));
        $response->headers->set('HX-Trigger', 'maintenance-windows-changed');

        return $response;
    }

    #[Route(path: '/{id}/delete', name: 'admin_maintenance_windows_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $window = $this->windowRepository->find($id);
        if ($window === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $window->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $this->auditLogger->log($actor, 'maintenance_window.deleted', [
            'window_id' => $window->getId(),
            'site_id' => $window->getSiteId(),
            'title' => $window->getTitle(),
        ]);

        $this->entityManager->remove($window);
        $this->entityManager->flush();

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->headers->set('HX-Trigger', 'maintenance-windows-changed');

        return $response;
    }

    private function parsePayload(Request $request): array
    {
        $errors = [];

        $title = trim((string) $request->request->get('title', ''));
        $message = trim((string) $request->request->get('message', ''));
        $visiblePublic = $request->request->get('visible_public') === 'on';
        $startAt = $this->toDateTime($request->request->get('start_at'));
        $endAt = $this->toDateTime($request->request->get('end_at'));
        $componentIds = $this->toIntArray($request->request->all('affected_components'));

        if ($title === '') {
            $errors[] = 'Title is required.';
        }
        if (!$startAt instanceof \DateTimeImmutable) {
            $errors[] = 'Start time is required.';
        }
        if (!$endAt instanceof \DateTimeImmutable) {
            $errors[] = 'End time is required.';
        }
        if ($startAt instanceof \DateTimeImmutable && $endAt instanceof \DateTimeImmutable && $endAt <= $startAt) {
            $errors[] = 'End time must be after the start time.';
        }

        return [
            'errors' => $errors,
            'title' => $title,
            'message' => $message === '' ? null : $message,
            'visible_public' => $visiblePublic,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'affected_component_ids' => $componentIds,
        ];
    }

    private function buildFormContext(?MaintenanceWindow $window = null, ?array $override = null): array
    {
        $data = [
            'id' => $window?->getId(),
            'title' => $window?->getTitle() ?? '',
            'message' => $window?->getMessage() ?? '',
            'start_at' => $window?->getStartAt()?->format('Y-m-d\TH:i') ?? '',
            'end_at' => $window?->getEndAt()?->format('Y-m-d\TH:i') ?? '',
            'visible_public' => $window?->isVisiblePublic() ?? false,
            'affected_component_ids' => $window ? $this->extractComponentIds($window->getAffectedComponents()) : [],
            'errors' => [],
            'action' => $window === null ? 'create' : 'update',
            'submit_label' => $window === null ? 'Create Window' : 'Update Window',
            'submit_color' => $window === null ? 'bg-indigo-600' : 'bg-amber-500',
            'action_url' => $window === null ? '/admin/status/maintenance' : sprintf('/admin/status/maintenance/%d', $window->getId()),
        ];

        if ($override !== null) {
            $data = array_merge($data, $override);
        }

        return $data;
    }

    private function renderFormWithErrors(Request $request, array $formData, int $status, ?MaintenanceWindow $window = null): Response
    {
        $site = $this->siteResolver->resolve($request);
        $components = [];
        if ($site !== null) {
            $components = $this->componentRepository->findBy(['siteId' => $site->getId()], ['name' => 'ASC']);
        }

        $formContext = $this->buildFormContext($window, [
            'title' => $formData['title'],
            'message' => $formData['message'] ?? '',
            'start_at' => $formData['start_at']?->format('Y-m-d\TH:i') ?? '',
            'end_at' => $formData['end_at']?->format('Y-m-d\TH:i') ?? '',
            'visible_public' => $formData['visible_public'],
            'affected_component_ids' => $formData['affected_component_ids'],
            'errors' => $formData['errors'],
        ]);

        return new Response($this->twig->render('admin/status/maintenance/_form.html.twig', [
            'form' => $formContext,
            'components' => $this->normalizeComponents($components),
        ]), $status);
    }

    /**
     * @param MaintenanceWindow[] $windows
     */
    private function buildSummary(array $windows): array
    {
        $summary = [
            'total' => count($windows),
            'public' => 0,
            'hidden' => 0,
        ];

        foreach ($windows as $window) {
            if ($window->isVisiblePublic()) {
                $summary['public']++;
            } else {
                $summary['hidden']++;
            }
        }

        return $summary;
    }

    /**
     * @param MaintenanceWindow[] $windows
     */
    private function normalizeWindows(array $windows): array
    {
        return array_map(function (MaintenanceWindow $window): array {
            return [
                'id' => $window->getId(),
                'title' => $window->getTitle(),
                'start_at' => $window->getStartAt(),
                'end_at' => $window->getEndAt(),
                'message' => $window->getMessage(),
                'visible_public' => $window->isVisiblePublic(),
                'components' => $this->normalizeComponents($window->getAffectedComponents()->toArray()),
            ];
        }, $windows);
    }

    /**
     * @param StatusComponent[] $components
     */
    private function normalizeComponents(array $components): array
    {
        return array_map(static function (StatusComponent $component): array {
            return [
                'id' => $component->getId(),
                'name' => $component->getName(),
            ];
        }, $components);
    }

    /**
     * @param iterable<StatusComponent> $components
     * @return int[]
     */
    private function extractComponentIds(iterable $components): array
    {
        $ids = [];
        foreach ($components as $component) {
            if ($component->getId() !== null) {
                $ids[] = $component->getId();
            }
        }

        return $ids;
    }

    /**
     * @param int[] $componentIds
     * @return StatusComponent[]
     */
    private function resolveComponents(int $siteId, array $componentIds): array
    {
        if ($componentIds === []) {
            return [];
        }

        $components = $this->componentRepository->findBy(['siteId' => $siteId, 'id' => $componentIds]);
        $componentsById = [];
        foreach ($components as $component) {
            if ($component->getId() !== null) {
                $componentsById[$component->getId()] = $component;
            }
        }

        $resolved = [];
        foreach ($componentIds as $componentId) {
            if (isset($componentsById[$componentId])) {
                $resolved[] = $componentsById[$componentId];
            }
        }

        return $resolved;
    }

    /**
     * @param mixed[] $values
     * @return int[]
     */
    private function toIntArray(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $intValue = $this->toInt($value);
            if ($intValue !== null) {
                $result[] = $intValue;
            }
        }

        return array_values(array_unique($result));
    }

    private function toInt(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function toDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);
        if ($date instanceof \DateTimeImmutable) {
            return $date;
        }

        return null;
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->getType() === UserType::Admin;
    }
}
