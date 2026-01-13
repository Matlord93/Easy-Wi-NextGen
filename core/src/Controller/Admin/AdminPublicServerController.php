<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\PublicServer;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\PublicServerRepository;
use App\Service\AuditLogger;
use App\Service\PublicServerValidator;
use App\Service\SiteResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/servers')]
final class AdminPublicServerController
{
    public function __construct(
        private readonly PublicServerRepository $publicServerRepository,
        private readonly SiteResolver $siteResolver,
        private readonly PublicServerValidator $serverValidator,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_public_servers', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $servers = $this->publicServerRepository->findBy(['siteId' => $site->getId()], ['sortOrder' => 'ASC']);

        return new Response($this->twig->render('admin/servers/index.html.twig', [
            'servers' => $this->normalizeServers($servers),
            'summary' => $this->buildSummary($servers),
            'form' => $this->buildFormContext(),
            'activeNav' => 'public-servers',
        ]));
    }

    #[Route(path: '/table', name: 'admin_public_servers_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $servers = $this->publicServerRepository->findBy(['siteId' => $site->getId()], ['sortOrder' => 'ASC']);

        return new Response($this->twig->render('admin/servers/_table.html.twig', [
            'servers' => $this->normalizeServers($servers),
        ]));
    }

    #[Route(path: '/form', name: 'admin_public_servers_form', methods: ['GET'])]
    public function form(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/servers/_form.html.twig', [
            'form' => $this->buildFormContext(),
        ]));
    }

    #[Route(path: '/{id}/edit', name: 'admin_public_servers_edit', methods: ['GET'])]
    public function edit(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $server = $this->publicServerRepository->find($id);
        if ($server === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $server->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('admin/servers/_form.html.twig', [
            'form' => $this->buildFormContext($server),
        ]));
    }

    #[Route(path: '', name: 'admin_public_servers_create', methods: ['POST'])]
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

        $formData = $this->parsePayload($request, $site->allowsPrivateNetworkTargets());
        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST);
        }

        $server = new PublicServer(
            siteId: $site->getId() ?? 0,
            name: $formData['name'],
            category: $formData['category'],
            gameKey: $formData['game_key'],
            ip: $formData['ip'],
            port: $formData['port'],
            queryType: $formData['query_type'],
            checkIntervalSeconds: $formData['check_interval_seconds'],
            createdBy: $actor,
            queryPort: $formData['query_port'],
            visiblePublic: $formData['visible_public'],
            visibleLoggedIn: $formData['visible_logged_in'],
            sortOrder: $formData['sort_order'],
            notesInternal: $formData['notes_internal'],
        );

        $this->entityManager->persist($server);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'public_server.created', [
            'server_id' => $server->getId(),
            'site_id' => $site->getId(),
            'name' => $server->getName(),
            'game_key' => $server->getGameKey(),
            'ip' => $server->getIp(),
            'port' => $server->getPort(),
            'visible_public' => $server->isVisiblePublic(),
        ]);
        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/servers/_form.html.twig', [
            'form' => $this->buildFormContext(),
        ]));
        $response->headers->set('HX-Trigger', 'public-servers-changed');

        return $response;
    }

    #[Route(path: '/{id}', name: 'admin_public_servers_update', methods: ['POST'])]
    public function update(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $server = $this->publicServerRepository->find($id);
        if ($server === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $server->getSiteId() !== $site->getId()) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $formData = $this->parsePayload($request, $site->allowsPrivateNetworkTargets());
        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST, $server);
        }

        $previous = [
            'name' => $server->getName(),
            'category' => $server->getCategory(),
            'game_key' => $server->getGameKey(),
            'ip' => $server->getIp(),
            'port' => $server->getPort(),
            'query_type' => $server->getQueryType(),
            'query_port' => $server->getQueryPort(),
            'visible_public' => $server->isVisiblePublic(),
            'visible_logged_in' => $server->isVisibleLoggedIn(),
            'sort_order' => $server->getSortOrder(),
            'notes_internal' => $server->getNotesInternal(),
            'check_interval_seconds' => $server->getCheckIntervalSeconds(),
        ];

        $server->setName($formData['name']);
        $server->setCategory($formData['category']);
        $server->setGameKey($formData['game_key']);
        $server->setIp($formData['ip']);
        $server->setPort($formData['port']);
        $server->setQueryType($formData['query_type']);
        $server->setQueryPort($formData['query_port']);
        $server->setVisiblePublic($formData['visible_public']);
        $server->setVisibleLoggedIn($formData['visible_logged_in']);
        $server->setSortOrder($formData['sort_order']);
        $server->setNotesInternal($formData['notes_internal']);
        $server->setCheckIntervalSeconds($formData['check_interval_seconds']);

        $this->auditLogger->log($actor, 'public_server.updated', [
            'server_id' => $server->getId(),
            'site_id' => $server->getSiteId(),
            'name' => $server->getName(),
            'game_key' => $server->getGameKey(),
            'visible_public' => $server->isVisiblePublic(),
            'previous' => $previous,
        ]);

        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/servers/_form.html.twig', [
            'form' => $this->buildFormContext(),
        ]));
        $response->headers->set('HX-Trigger', 'public-servers-changed');

        return $response;
    }

    #[Route(path: '/{id}/delete', name: 'admin_public_servers_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $server = $this->publicServerRepository->find($id);
        if ($server === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $server->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $this->auditLogger->log($actor, 'public_server.deleted', [
            'server_id' => $server->getId(),
            'site_id' => $server->getSiteId(),
            'name' => $server->getName(),
            'game_key' => $server->getGameKey(),
        ]);

        $this->entityManager->remove($server);
        $this->entityManager->flush();

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->headers->set('HX-Trigger', 'public-servers-changed');

        return $response;
    }

    private function parsePayload(Request $request, bool $allowPrivateTargets): array
    {
        $errors = [];

        $name = trim((string) $request->request->get('name', ''));
        $category = trim((string) $request->request->get('category', ''));
        $gameKey = trim((string) $request->request->get('game_key', ''));
        $ip = trim((string) $request->request->get('ip', ''));
        $port = $this->toInt($request->request->get('port'));
        $queryType = trim((string) $request->request->get('query_type', ''));
        $queryPort = $this->toInt($request->request->get('query_port'));
        $checkIntervalSeconds = $this->toInt($request->request->get('check_interval_seconds')) ?? 60;
        $visiblePublic = $request->request->get('visible_public') === 'on';
        $visibleLoggedIn = $request->request->get('visible_logged_in') === 'on';
        $sortOrder = $this->toInt($request->request->get('sort_order')) ?? 0;
        $notesInternal = trim((string) $request->request->get('notes_internal', ''));

        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if ($category === '') {
            $errors[] = 'Category is required.';
        }
        if ($gameKey === '') {
            $errors[] = 'Game key is required.';
        }
        if ($queryType === '') {
            $errors[] = 'Query type is required.';
        }

        $errors = array_merge($errors, $this->serverValidator->validate(
            $ip,
            $port,
            $queryPort,
            $checkIntervalSeconds,
            $allowPrivateTargets,
        ));

        return [
            'errors' => $errors,
            'name' => $name,
            'category' => $category,
            'game_key' => $gameKey,
            'ip' => $ip,
            'port' => $port ?? 0,
            'query_type' => $queryType,
            'query_port' => $queryPort,
            'check_interval_seconds' => $checkIntervalSeconds,
            'visible_public' => $visiblePublic,
            'visible_logged_in' => $visibleLoggedIn,
            'sort_order' => $sortOrder,
            'notes_internal' => $notesInternal === '' ? null : $notesInternal,
        ];
    }

    private function buildFormContext(?PublicServer $server = null, ?array $override = null): array
    {
        $data = [
            'id' => $server?->getId(),
            'name' => $server?->getName() ?? '',
            'category' => $server?->getCategory() ?? '',
            'game_key' => $server?->getGameKey() ?? '',
            'ip' => $server?->getIp() ?? '',
            'port' => $server?->getPort() ?? 27015,
            'query_type' => $server?->getQueryType() ?? 'steam_a2s',
            'query_port' => $server?->getQueryPort(),
            'check_interval_seconds' => $server?->getCheckIntervalSeconds() ?? 60,
            'visible_public' => $server?->isVisiblePublic() ?? false,
            'visible_logged_in' => $server?->isVisibleLoggedIn() ?? false,
            'sort_order' => $server?->getSortOrder() ?? 0,
            'notes_internal' => $server?->getNotesInternal() ?? '',
            'errors' => [],
            'action' => $server === null ? 'create' : 'update',
            'submit_label' => $server === null ? 'Create Server' : 'Update Server',
            'submit_color' => $server === null ? 'bg-indigo-600' : 'bg-amber-500',
            'action_url' => $server === null ? '/admin/servers' : sprintf('/admin/servers/%d', $server->getId()),
        ];

        if ($override !== null) {
            $data = array_merge($data, $override);
        }

        return $data;
    }

    private function renderFormWithErrors(array $formData, int $status, ?PublicServer $server = null): Response
    {
        $formContext = $this->buildFormContext($server, [
            'name' => $formData['name'],
            'category' => $formData['category'],
            'game_key' => $formData['game_key'],
            'ip' => $formData['ip'],
            'port' => $formData['port'],
            'query_type' => $formData['query_type'],
            'query_port' => $formData['query_port'],
            'check_interval_seconds' => $formData['check_interval_seconds'],
            'visible_public' => $formData['visible_public'],
            'visible_logged_in' => $formData['visible_logged_in'],
            'sort_order' => $formData['sort_order'],
            'notes_internal' => $formData['notes_internal'] ?? '',
            'errors' => $formData['errors'],
        ]);

        return new Response($this->twig->render('admin/servers/_form.html.twig', [
            'form' => $formContext,
        ]), $status);
    }

    /**
     * @param PublicServer[] $servers
     */
    private function buildSummary(array $servers): array
    {
        $summary = [
            'total' => count($servers),
            'public' => 0,
            'hidden' => 0,
        ];

        foreach ($servers as $server) {
            if ($server->isVisiblePublic()) {
                $summary['public']++;
            } else {
                $summary['hidden']++;
            }
        }

        return $summary;
    }

    /**
     * @param PublicServer[] $servers
     */
    private function normalizeServers(array $servers): array
    {
        return array_map(function (PublicServer $server): array {
            $statusCache = $server->getStatusCache();
            $status = $statusCache['status'] ?? ($statusCache['online'] ?? null);
            $statusLabel = is_string($status) ? $status : ($status === true ? 'online' : 'unknown');

            return [
                'id' => $server->getId(),
                'name' => $server->getName(),
                'game_key' => $server->getGameKey(),
                'address' => sprintf('%s:%d', $server->getIp(), $server->getPort()),
                'query_type' => $server->getQueryType(),
                'visible_public' => $server->isVisiblePublic(),
                'visible_logged_in' => $server->isVisibleLoggedIn(),
                'sort_order' => $server->getSortOrder(),
                'last_checked_at' => $server->getLastCheckedAt(),
                'check_interval_seconds' => $server->getCheckIntervalSeconds(),
                'status' => $statusLabel,
            ];
        }, $servers);
    }

    private function toInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->isAdmin();
    }
}
