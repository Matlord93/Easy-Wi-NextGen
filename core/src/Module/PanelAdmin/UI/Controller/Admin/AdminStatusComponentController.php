<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\StatusComponent;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\StatusComponentRepository;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\SiteResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/status/components')]
final class AdminStatusComponentController
{
    private const TYPES = ['node', 'service', 'url'];
    private const STATUSES = ['operational', 'degraded', 'outage', 'maintenance'];

    public function __construct(
        private readonly StatusComponentRepository $componentRepository,
        private readonly SiteResolver $siteResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_status_components', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $components = $this->componentRepository->findBy(['siteId' => $site->getId()], ['name' => 'ASC']);

        return new Response($this->twig->render('admin/status/components/index.html.twig', [
            'components' => $this->normalizeComponents($components),
            'summary' => $this->buildSummary($components),
            'form' => $this->buildFormContext(),
            'types' => self::TYPES,
            'statuses' => self::STATUSES,
            'activeNav' => 'status-components',
        ]));
    }

    #[Route(path: '/table', name: 'admin_status_components_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $components = $this->componentRepository->findBy(['siteId' => $site->getId()], ['name' => 'ASC']);

        return new Response($this->twig->render('admin/status/components/_table.html.twig', [
            'components' => $this->normalizeComponents($components),
        ]));
    }

    #[Route(path: '/form', name: 'admin_status_components_form', methods: ['GET'])]
    public function form(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/status/components/_form.html.twig', [
            'form' => $this->buildFormContext(),
            'types' => self::TYPES,
            'statuses' => self::STATUSES,
        ]));
    }

    #[Route(path: '/{id}/edit', name: 'admin_status_components_edit', methods: ['GET'])]
    public function edit(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $component = $this->componentRepository->find($id);
        if ($component === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $component->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('admin/status/components/_form.html.twig', [
            'form' => $this->buildFormContext($component),
            'types' => self::TYPES,
            'statuses' => self::STATUSES,
        ]));
    }

    #[Route(path: '', name: 'admin_status_components_create', methods: ['POST'])]
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

        $component = new StatusComponent(
            siteId: $site->getId() ?? 0,
            name: $formData['name'],
            type: $formData['type'],
            targetRef: $formData['target_ref'],
            status: $formData['status'],
            visiblePublic: $formData['visible_public'],
            lastCheckedAt: $formData['last_checked_at'],
        );

        $this->entityManager->persist($component);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'status_component.created', [
            'component_id' => $component->getId(),
            'site_id' => $component->getSiteId(),
            'name' => $component->getName(),
            'type' => $component->getType(),
            'status' => $component->getStatus(),
            'visible_public' => $component->isVisiblePublic(),
        ]);
        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/status/components/_form.html.twig', [
            'form' => $this->buildFormContext(),
            'types' => self::TYPES,
            'statuses' => self::STATUSES,
        ]));
        $response->headers->set('HX-Trigger', 'status-components-changed');

        return $response;
    }

    #[Route(path: '/{id}', name: 'admin_status_components_update', methods: ['POST'])]
    public function update(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $component = $this->componentRepository->find($id);
        if ($component === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $component->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $formData = $this->parsePayload($request);
        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST, $component);
        }

        $previous = [
            'name' => $component->getName(),
            'type' => $component->getType(),
            'target_ref' => $component->getTargetRef(),
            'status' => $component->getStatus(),
            'visible_public' => $component->isVisiblePublic(),
            'last_checked_at' => $component->getLastCheckedAt()?->format(DATE_RFC3339),
        ];

        $component->setName($formData['name']);
        $component->setType($formData['type']);
        $component->setTargetRef($formData['target_ref']);
        $component->setStatus($formData['status']);
        $component->setVisiblePublic($formData['visible_public']);
        $component->setLastCheckedAt($formData['last_checked_at']);

        $this->auditLogger->log($actor, 'status_component.updated', [
            'component_id' => $component->getId(),
            'site_id' => $component->getSiteId(),
            'name' => $component->getName(),
            'status' => $component->getStatus(),
            'visible_public' => $component->isVisiblePublic(),
            'previous' => $previous,
        ]);

        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/status/components/_form.html.twig', [
            'form' => $this->buildFormContext(),
            'types' => self::TYPES,
            'statuses' => self::STATUSES,
        ]));
        $response->headers->set('HX-Trigger', 'status-components-changed');

        return $response;
    }

    #[Route(path: '/{id}/delete', name: 'admin_status_components_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $component = $this->componentRepository->find($id);
        if ($component === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $component->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $this->auditLogger->log($actor, 'status_component.deleted', [
            'component_id' => $component->getId(),
            'site_id' => $component->getSiteId(),
            'name' => $component->getName(),
        ]);

        $this->entityManager->remove($component);
        $this->entityManager->flush();

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->headers->set('HX-Trigger', 'status-components-changed');

        return $response;
    }

    private function parsePayload(Request $request): array
    {
        $errors = [];

        $name = trim((string) $request->request->get('name', ''));
        $type = trim((string) $request->request->get('type', ''));
        $targetRef = trim((string) $request->request->get('target_ref', ''));
        $status = trim((string) $request->request->get('status', ''));
        $visiblePublic = $request->request->get('visible_public') === 'on';
        $lastCheckedAt = $this->toDateTime($request->request->get('last_checked_at'));

        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if ($targetRef === '') {
            $errors[] = 'Target reference is required.';
        }
        if (!in_array($type, self::TYPES, true)) {
            $errors[] = 'Type must be one of: ' . implode(', ', self::TYPES) . '.';
        }
        if (!in_array($status, self::STATUSES, true)) {
            $errors[] = 'Status must be one of: ' . implode(', ', self::STATUSES) . '.';
        }

        return [
            'errors' => $errors,
            'name' => $name,
            'type' => $type,
            'target_ref' => $targetRef,
            'status' => $status,
            'visible_public' => $visiblePublic,
            'last_checked_at' => $lastCheckedAt,
        ];
    }

    private function buildFormContext(?StatusComponent $component = null, ?array $override = null): array
    {
        $data = [
            'id' => $component?->getId(),
            'name' => $component?->getName() ?? '',
            'type' => $component?->getType() ?? self::TYPES[0],
            'target_ref' => $component?->getTargetRef() ?? '',
            'status' => $component?->getStatus() ?? self::STATUSES[0],
            'visible_public' => $component?->isVisiblePublic() ?? false,
            'last_checked_at' => $component?->getLastCheckedAt()?->format('Y-m-d\TH:i') ?? '',
            'errors' => [],
            'action' => $component === null ? 'create' : 'update',
            'submit_label' => $component === null ? 'Create Component' : 'Update Component',
            'submit_color' => $component === null ? 'bg-indigo-600' : 'bg-amber-500',
            'action_url' => $component === null ? '/admin/status/components' : sprintf('/admin/status/components/%d', $component->getId()),
        ];

        if ($override !== null) {
            $data = array_merge($data, $override);
        }

        return $data;
    }

    private function renderFormWithErrors(array $formData, int $status, ?StatusComponent $component = null): Response
    {
        $formContext = $this->buildFormContext($component, [
            'name' => $formData['name'],
            'type' => $formData['type'],
            'target_ref' => $formData['target_ref'],
            'status' => $formData['status'],
            'visible_public' => $formData['visible_public'],
            'last_checked_at' => $formData['last_checked_at']?->format('Y-m-d\TH:i') ?? '',
            'errors' => $formData['errors'],
        ]);

        return new Response($this->twig->render('admin/status/components/_form.html.twig', [
            'form' => $formContext,
            'types' => self::TYPES,
            'statuses' => self::STATUSES,
        ]), $status);
    }

    /**
     * @param StatusComponent[] $components
     */
    private function buildSummary(array $components): array
    {
        $summary = [
            'total' => count($components),
            'public' => 0,
            'hidden' => 0,
        ];

        foreach ($components as $component) {
            if ($component->isVisiblePublic()) {
                $summary['public']++;
            } else {
                $summary['hidden']++;
            }
        }

        return $summary;
    }

    /**
     * @param StatusComponent[] $components
     */
    private function normalizeComponents(array $components): array
    {
        return array_map(function (StatusComponent $component): array {
            return [
                'id' => $component->getId(),
                'name' => $component->getName(),
                'type' => $component->getType(),
                'target_ref' => $component->getTargetRef(),
                'status' => $component->getStatus(),
                'last_checked_at' => $component->getLastCheckedAt(),
                'visible_public' => $component->isVisiblePublic(),
            ];
        }, $components);
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

        return $actor instanceof User && $actor->isAdmin();
    }
}
