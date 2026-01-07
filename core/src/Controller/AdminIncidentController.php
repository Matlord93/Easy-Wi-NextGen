<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Incident;
use App\Entity\IncidentUpdate;
use App\Entity\StatusComponent;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\IncidentRepository;
use App\Repository\IncidentUpdateRepository;
use App\Repository\StatusComponentRepository;
use App\Service\AuditLogger;
use App\Service\SiteResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/status/incidents')]
final class AdminIncidentController
{
    private const STATUSES = ['investigating', 'identified', 'monitoring', 'resolved'];

    public function __construct(
        private readonly IncidentRepository $incidentRepository,
        private readonly IncidentUpdateRepository $updateRepository,
        private readonly StatusComponentRepository $componentRepository,
        private readonly SiteResolver $siteResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_incidents', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $incidents = $this->incidentRepository->findBy(['siteId' => $site->getId()], ['startedAt' => 'DESC']);
        $components = $this->componentRepository->findBy(['siteId' => $site->getId()], ['name' => 'ASC']);

        return new Response($this->twig->render('admin/status/incidents/index.html.twig', [
            'incidents' => $this->normalizeIncidents($incidents),
            'summary' => $this->buildSummary($incidents),
            'form' => $this->buildFormContext(),
            'components' => $this->normalizeComponents($components),
            'statuses' => self::STATUSES,
            'activeNav' => 'status-incidents',
        ]));
    }

    #[Route(path: '/table', name: 'admin_incidents_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $incidents = $this->incidentRepository->findBy(['siteId' => $site->getId()], ['startedAt' => 'DESC']);

        return new Response($this->twig->render('admin/status/incidents/_table.html.twig', [
            'incidents' => $this->normalizeIncidents($incidents),
        ]));
    }

    #[Route(path: '/form', name: 'admin_incidents_form', methods: ['GET'])]
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

        return new Response($this->twig->render('admin/status/incidents/_form.html.twig', [
            'form' => $this->buildFormContext(),
            'components' => $this->normalizeComponents($components),
            'statuses' => self::STATUSES,
        ]));
    }

    #[Route(path: '/{id}/edit', name: 'admin_incidents_edit', methods: ['GET'])]
    public function edit(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $incident = $this->incidentRepository->find($id);
        if ($incident === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $incident->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $components = $this->componentRepository->findBy(['siteId' => $site->getId()], ['name' => 'ASC']);

        return new Response($this->twig->render('admin/status/incidents/_form.html.twig', [
            'form' => $this->buildFormContext($incident),
            'components' => $this->normalizeComponents($components),
            'statuses' => self::STATUSES,
        ]));
    }

    #[Route(path: '', name: 'admin_incidents_create', methods: ['POST'])]
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

        $incident = new Incident(
            siteId: $site->getId() ?? 0,
            title: $formData['title'],
            status: $formData['status'],
            startedAt: $formData['started_at'],
            message: $formData['message'],
            visiblePublic: $formData['visible_public'],
            resolvedAt: $formData['resolved_at'],
        );

        $components = $this->resolveComponents($site->getId() ?? 0, $formData['affected_component_ids']);
        $incident->syncAffectedComponents($components);

        $this->entityManager->persist($incident);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'incident.created', [
            'incident_id' => $incident->getId(),
            'site_id' => $incident->getSiteId(),
            'title' => $incident->getTitle(),
            'status' => $incident->getStatus(),
            'visible_public' => $incident->isVisiblePublic(),
            'affected_component_ids' => array_map(static fn (StatusComponent $component): int => $component->getId() ?? 0, $components),
        ]);
        $this->entityManager->flush();

        $componentsForForm = $this->componentRepository->findBy(['siteId' => $site->getId()], ['name' => 'ASC']);

        $response = new Response($this->twig->render('admin/status/incidents/_form.html.twig', [
            'form' => $this->buildFormContext(),
            'components' => $this->normalizeComponents($componentsForForm),
            'statuses' => self::STATUSES,
        ]));
        $response->headers->set('HX-Trigger', 'incidents-changed');

        return $response;
    }

    #[Route(path: '/{id}', name: 'admin_incidents_update', methods: ['POST'])]
    public function update(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $incident = $this->incidentRepository->find($id);
        if ($incident === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $incident->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $formData = $this->parsePayload($request, $incident);
        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($request, $formData, Response::HTTP_BAD_REQUEST, $incident);
        }

        $previous = [
            'title' => $incident->getTitle(),
            'status' => $incident->getStatus(),
            'visible_public' => $incident->isVisiblePublic(),
            'started_at' => $incident->getStartedAt()->format(DATE_RFC3339),
            'resolved_at' => $incident->getResolvedAt()?->format(DATE_RFC3339),
        ];

        $incident->setTitle($formData['title']);
        $incident->setStatus($formData['status']);
        $incident->setMessage($formData['message']);
        $incident->setVisiblePublic($formData['visible_public']);
        $incident->setStartedAt($formData['started_at']);
        $incident->setResolvedAt($formData['resolved_at']);

        $components = $this->resolveComponents($site->getId() ?? 0, $formData['affected_component_ids']);
        $incident->syncAffectedComponents($components);

        $this->auditLogger->log($actor, 'incident.updated', [
            'incident_id' => $incident->getId(),
            'site_id' => $incident->getSiteId(),
            'title' => $incident->getTitle(),
            'status' => $incident->getStatus(),
            'visible_public' => $incident->isVisiblePublic(),
            'affected_component_ids' => array_map(static fn (StatusComponent $component): int => $component->getId() ?? 0, $components),
            'previous' => $previous,
        ]);

        $this->entityManager->flush();

        $componentsForForm = $this->componentRepository->findBy(['siteId' => $site->getId()], ['name' => 'ASC']);

        $response = new Response($this->twig->render('admin/status/incidents/_form.html.twig', [
            'form' => $this->buildFormContext(),
            'components' => $this->normalizeComponents($componentsForForm),
            'statuses' => self::STATUSES,
        ]));
        $response->headers->set('HX-Trigger', 'incidents-changed');

        return $response;
    }

    #[Route(path: '/{id}', name: 'admin_incidents_show', methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $incident = $this->incidentRepository->find($id);
        if ($incident === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $incident->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $updates = $this->updateRepository->findByIncident($incident);

        return new Response($this->twig->render('admin/status/incidents/show.html.twig', [
            'incident' => $this->normalizeIncident($incident),
            'updates' => $this->normalizeUpdates($updates),
            'updateForm' => $this->buildUpdateFormContext($incident),
            'statuses' => self::STATUSES,
            'activeNav' => 'status-incidents',
        ]));
    }

    #[Route(path: '/{id}/updates', name: 'admin_incidents_updates_create', methods: ['POST'])]
    public function createUpdate(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $incident = $this->incidentRepository->find($id);
        if ($incident === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $incident->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $status = trim((string) $request->request->get('status', ''));
        $message = trim((string) $request->request->get('message', ''));

        $errors = [];
        if (!in_array($status, self::STATUSES, true)) {
            $errors[] = 'Status is invalid.';
        }
        if ($message === '') {
            $errors[] = 'Message is required.';
        }

        if ($errors !== []) {
            $formContext = $this->buildUpdateFormContext($incident, [
                'status' => $status,
                'message' => $message,
                'errors' => $errors,
            ]);

            return new Response($this->twig->render('admin/status/incidents/_update_form.html.twig', [
                'updateForm' => $formContext,
                'statuses' => self::STATUSES,
            ]), Response::HTTP_BAD_REQUEST);
        }

        $update = new IncidentUpdate($incident, $status, $message, $actor);
        $this->entityManager->persist($update);

        $incident->setStatus($status);
        if ($status === 'resolved' && $incident->getResolvedAt() === null) {
            $incident->setResolvedAt(new \DateTimeImmutable());
        }

        $this->auditLogger->log($actor, 'incident.update.created', [
            'incident_id' => $incident->getId(),
            'site_id' => $incident->getSiteId(),
            'status' => $status,
            'message' => $message,
        ]);

        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/status/incidents/_update_form.html.twig', [
            'updateForm' => $this->buildUpdateFormContext($incident),
            'statuses' => self::STATUSES,
        ]));
        $response->headers->set('HX-Trigger', 'incident-updates-changed');

        return $response;
    }

    #[Route(path: '/{id}/updates', name: 'admin_incidents_updates_table', methods: ['GET'])]
    public function updates(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $incident = $this->incidentRepository->find($id);
        if ($incident === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $incident->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $updates = $this->updateRepository->findByIncident($incident);

        return new Response($this->twig->render('admin/status/incidents/_updates.html.twig', [
            'updates' => $this->normalizeUpdates($updates),
        ]));
    }

    #[Route(path: '/{id}/delete', name: 'admin_incidents_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $incident = $this->incidentRepository->find($id);
        if ($incident === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $incident->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $this->auditLogger->log($actor, 'incident.deleted', [
            'incident_id' => $incident->getId(),
            'site_id' => $incident->getSiteId(),
            'title' => $incident->getTitle(),
        ]);

        $this->entityManager->remove($incident);
        $this->entityManager->flush();

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->headers->set('HX-Trigger', 'incidents-changed');

        return $response;
    }

    private function parsePayload(Request $request, ?Incident $incident = null): array
    {
        $errors = [];

        $title = trim((string) $request->request->get('title', ''));
        $status = trim((string) $request->request->get('status', ''));
        $message = trim((string) $request->request->get('message', ''));
        $visiblePublic = $request->request->get('visible_public') === 'on';
        $startedAt = $this->toDateTime($request->request->get('started_at'));
        $resolvedAt = $this->toDateTime($request->request->get('resolved_at'));
        $componentIds = $this->toIntArray($request->request->all('affected_components'));

        if ($title === '') {
            $errors[] = 'Title is required.';
        }
        if (!in_array($status, self::STATUSES, true)) {
            $errors[] = 'Status is invalid.';
        }
        if (!$startedAt instanceof \DateTimeImmutable) {
            $errors[] = 'Start time is required.';
        }
        if ($status === 'resolved' && $resolvedAt === null) {
            $resolvedAt = new \DateTimeImmutable();
        }
        if ($resolvedAt !== null && $startedAt instanceof \DateTimeImmutable && $resolvedAt < $startedAt) {
            $errors[] = 'Resolved time must be after the start time.';
        }
        if ($incident !== null && $incident->getStatus() !== 'resolved' && $status === 'resolved' && $resolvedAt === null) {
            $resolvedAt = new \DateTimeImmutable();
        }

        return [
            'errors' => $errors,
            'title' => $title,
            'status' => $status,
            'message' => $message === '' ? null : $message,
            'visible_public' => $visiblePublic,
            'started_at' => $startedAt,
            'resolved_at' => $resolvedAt,
            'affected_component_ids' => $componentIds,
        ];
    }

    private function buildFormContext(?Incident $incident = null, ?array $override = null): array
    {
        $data = [
            'id' => $incident?->getId(),
            'title' => $incident?->getTitle() ?? '',
            'status' => $incident?->getStatus() ?? self::STATUSES[0],
            'message' => $incident?->getMessage() ?? '',
            'visible_public' => $incident?->isVisiblePublic() ?? false,
            'started_at' => $incident?->getStartedAt()?->format('Y-m-d\TH:i') ?? '',
            'resolved_at' => $incident?->getResolvedAt()?->format('Y-m-d\TH:i') ?? '',
            'affected_component_ids' => $incident ? $this->extractComponentIds($incident->getAffectedComponents()) : [],
            'errors' => [],
            'action' => $incident === null ? 'create' : 'update',
            'submit_label' => $incident === null ? 'Create Incident' : 'Update Incident',
            'submit_color' => $incident === null ? 'bg-indigo-600' : 'bg-amber-500',
            'action_url' => $incident === null ? '/admin/status/incidents' : sprintf('/admin/status/incidents/%d', $incident->getId()),
        ];

        if ($override !== null) {
            $data = array_merge($data, $override);
        }

        return $data;
    }

    private function buildUpdateFormContext(Incident $incident, ?array $override = null): array
    {
        $data = [
            'incident_id' => $incident->getId(),
            'status' => $incident->getStatus(),
            'message' => '',
            'errors' => [],
            'action_url' => sprintf('/admin/status/incidents/%d/updates', $incident->getId()),
        ];

        if ($override !== null) {
            $data = array_merge($data, $override);
        }

        return $data;
    }

    private function renderFormWithErrors(Request $request, array $formData, int $status, ?Incident $incident = null): Response
    {
        $site = $this->siteResolver->resolve($request);
        $components = [];
        if ($site !== null) {
            $components = $this->componentRepository->findBy(['siteId' => $site->getId()], ['name' => 'ASC']);
        }

        $formContext = $this->buildFormContext($incident, [
            'title' => $formData['title'],
            'status' => $formData['status'],
            'message' => $formData['message'] ?? '',
            'visible_public' => $formData['visible_public'],
            'started_at' => $formData['started_at']?->format('Y-m-d\TH:i') ?? '',
            'resolved_at' => $formData['resolved_at']?->format('Y-m-d\TH:i') ?? '',
            'affected_component_ids' => $formData['affected_component_ids'],
            'errors' => $formData['errors'],
        ]);

        return new Response($this->twig->render('admin/status/incidents/_form.html.twig', [
            'form' => $formContext,
            'components' => $this->normalizeComponents($components),
            'statuses' => self::STATUSES,
        ]), $status);
    }

    /**
     * @param Incident[] $incidents
     */
    private function buildSummary(array $incidents): array
    {
        $summary = [
            'total' => count($incidents),
            'open' => 0,
            'resolved' => 0,
        ];

        foreach ($incidents as $incident) {
            if ($incident->getStatus() === 'resolved') {
                $summary['resolved']++;
            } else {
                $summary['open']++;
            }
        }

        return $summary;
    }

    /**
     * @param Incident[] $incidents
     */
    private function normalizeIncidents(array $incidents): array
    {
        return array_map(function (Incident $incident): array {
            return $this->normalizeIncident($incident);
        }, $incidents);
    }

    private function normalizeIncident(Incident $incident): array
    {
        return [
            'id' => $incident->getId(),
            'title' => $incident->getTitle(),
            'status' => $incident->getStatus(),
            'message' => $incident->getMessage(),
            'visible_public' => $incident->isVisiblePublic(),
            'started_at' => $incident->getStartedAt(),
            'resolved_at' => $incident->getResolvedAt(),
            'components' => $this->normalizeComponents($incident->getAffectedComponents()->toArray()),
        ];
    }

    /**
     * @param IncidentUpdate[] $updates
     */
    private function normalizeUpdates(array $updates): array
    {
        return array_map(static function (IncidentUpdate $update): array {
            return [
                'id' => $update->getId(),
                'status' => $update->getStatus(),
                'message' => $update->getMessage(),
                'created_at' => $update->getCreatedAt(),
                'created_by' => $update->getCreatedBy()->getEmail(),
            ];
        }, $updates);
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
