<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\UI\Controller\Customer;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Application\Ts3\Ts3ViewerService;
use App\Module\Core\Application\Ts3\Ts3VirtualServerService;
use App\Module\Core\Domain\Entity\Ts3Token;
use App\Module\Core\Domain\Entity\Ts3VirtualServer;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\Dto\Ts3\ViewerDto;
use App\Module\Core\Form\Ts3ViewerType;
use App\Module\Teamspeak\Application\Query\ServerQueryLimiterInterface;
use App\Repository\Ts3TokenRepository;
use App\Repository\Ts3ViewerRepository;
use App\Repository\Ts3VirtualServerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Twig\Environment;

#[Route(path: '/customer/ts3/servers')]
/**
 * @deprecated since 2026-02. Unified customer voice SoT is /customer/voice.
 *             Kept for compatibility during migration horizon.
 */
final class CustomerTs3ServerController
{
    public function __construct(
        private readonly Ts3VirtualServerRepository $virtualServerRepository,
        private readonly Ts3TokenRepository $tokenRepository,
        private readonly Ts3ViewerRepository $viewerRepository,
        private readonly Ts3VirtualServerService $virtualServerService,
        private readonly Ts3ViewerService $viewerService,
        private readonly SecretsCrypto $crypto,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly FormFactoryInterface $formFactory,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
        private readonly CacheInterface $cache,
        private readonly ServerQueryLimiterInterface $queryLimiter,
    ) {
    }

    #[Route(path: '', name: 'customer_ts3_servers_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);

        $servers = $this->virtualServerRepository->findBy(
            ['customerId' => $customer->getId(), 'archivedAt' => null],
            ['updatedAt' => 'DESC'],
        );
        $serverRows = array_map(function (Ts3VirtualServer $server): array {
            $id = (int) $server->getId();
            return [
                'server' => $server,
                'connectIp' => $this->resolveExternalHost($server),
                'summaryUrl' => $this->urlGenerator->generate('customer_ts3_servers_summary', ['id' => $id]),
                'csrf' => [
                    'start' => $this->csrfTokenManager->getToken('ts3_server_start_' . $id)->getValue(),
                    'stop' => $this->csrfTokenManager->getToken('ts3_server_stop_' . $id)->getValue(),
                    'restart' => $this->csrfTokenManager->getToken('ts3_server_restart_' . $id)->getValue(),
                ],
            ];
        }, $servers);

        return new Response($this->twig->render('customer/ts3/servers/index.html.twig', [
            'activeNav' => 'ts3',
            'servers' => $serverRows,
        ]));
    }

    #[Route(path: '/{id}', name: 'customer_ts3_servers_show', methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($customer, $id);
        $token = $this->tokenRepository->findOneBy(['virtualServer' => $server, 'active' => true]);
        $tokens = $this->tokenRepository->findBy(['virtualServer' => $server], ['createdAt' => 'DESC']);
        $tokenRows = array_map(function (Ts3Token $entry): array {
            return [
                'token' => $entry->getToken($this->crypto),
                'type' => $entry->getType(),
                'active' => $entry->isActive(),
                'createdAt' => $entry->getCreatedAt(),
                'revokedAt' => $entry->getRevokedAt(),
            ];
        }, $tokens);

        $viewer = $this->viewerRepository->findOneBy(['virtualServer' => $server]);
        $dto = new ViewerDto(
            $viewer?->isEnabled() ?? true,
            $viewer?->getCacheTtlMs() ?? 1500,
            $viewer?->getDomainAllowlist() ?? null,
        );
        $form = $this->formFactory->create(Ts3ViewerType::class, $dto, [
            'action' => sprintf('/customer/ts3/servers/%d/viewer/save', $server->getId()),
        ]);

        return new Response($this->twig->render('customer/ts3/servers/show.html.twig', [
            'activeNav' => 'ts3',
            'server' => $server,
            'connectIp' => $this->resolveExternalHost($server),
            'externalHost' => $this->resolveExternalHost($server),
            'token' => $token instanceof Ts3Token ? $token->getToken($this->crypto) : null,
            'tokens' => $tokenRows,
            'viewer' => $viewer,
            'form' => $form->createView(),
            'csrf' => $this->csrfTokens($server),
        ]));
    }

    #[Route(path: '/{id}/logs', name: 'customer_ts3_servers_logs_page', methods: ['GET'])]
    public function logsPage(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($customer, $id);

        return new Response($this->twig->render('customer/ts3/servers/logs.html.twig', [
            'activeNav' => 'ts3',
            'server' => $server,
            'externalHost' => $this->resolveExternalHost($server),
        ]));
    }

    #[Route(path: '/{id}/backups', name: 'customer_ts3_servers_backups_page', methods: ['GET'])]
    public function backupsPage(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($customer, $id);

        return new Response($this->twig->render('customer/ts3/servers/backups.html.twig', [
            'activeNav' => 'ts3',
            'server' => $server,
            'externalHost' => $this->resolveExternalHost($server),
            'csrf' => $this->csrfTokens($server),
        ]));
    }

    #[Route(path: '/{id}/settings', name: 'customer_ts3_servers_settings', methods: ['GET'])]
    public function settings(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($customer, $id);

        return new Response($this->twig->render('customer/ts3/servers/_settings.html.twig', [
            'server' => $server,
            'connectIp' => $this->resolveExternalHost($server),
            'csrf' => [
                'settings' => $this->csrfTokenManager->getToken('ts3_server_settings_' . $id)->getValue(),
            ],
            'isAdmin' => $customer->isAdmin(),
        ]));
    }

    #[Route(path: '/{id}/settings/save', name: 'customer_ts3_servers_settings_save', methods: ['POST'])]
    public function saveSettings(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($customer, $id);
        $this->validateCsrf($request, 'ts3_server_settings_' . $id);

        $server->setPublicHost((string) $request->request->get('public_host'));
        $this->entityManager->flush();

        return new JsonResponse([
            'status' => 'ok',
            'message' => 'Einstellungen gespeichert.',
            'public_host' => $server->getPublicHost() ?? $this->resolveExternalHost($server),
        ]);
    }

    #[Route(path: '/{id}/start', name: 'customer_ts3_servers_start', methods: ['POST'])]
    public function start(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($customer, $id);
        $this->validateCsrf($request, 'ts3_server_start_' . $id);

        if ($this->rejectIfNotReady($customer, $server, $request, 'start') !== null) {
            return $this->redirectToServer($server);
        }

        $job = $this->virtualServerService->start($server);
        $this->auditLogger->log($customer, 'ts3.virtual.start', [
            'action_id' => $job->getId(),
            'virtual_server_id' => $server->getId(),
            'node_id' => $server->getNode()->getId(),
            'sid' => $server->getSid(),
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();
        $request->getSession()->getFlashBag()->add('success', sprintf('Server gestartet. Action ID: %s', $job->getId()));

        return $this->redirectToServer($server);
    }

    #[Route(path: '/{id}/stop', name: 'customer_ts3_servers_stop', methods: ['POST'])]
    public function stop(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($customer, $id);
        $this->validateCsrf($request, 'ts3_server_stop_' . $id);

        if ($this->rejectIfNotReady($customer, $server, $request, 'stop') !== null) {
            return $this->redirectToServer($server);
        }

        $job = $this->virtualServerService->stop($server);
        $this->auditLogger->log($customer, 'ts3.virtual.stop', [
            'action_id' => $job->getId(),
            'virtual_server_id' => $server->getId(),
            'node_id' => $server->getNode()->getId(),
            'sid' => $server->getSid(),
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();
        $request->getSession()->getFlashBag()->add('success', sprintf('Server gestoppt. Action ID: %s', $job->getId()));

        return $this->redirectToServer($server);
    }

    #[Route(path: '/{id}/restart', name: 'customer_ts3_servers_restart', methods: ['POST'])]
    public function restart(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($customer, $id);
        $this->validateCsrf($request, 'ts3_server_restart_' . $id);

        if ($this->rejectIfNotReady($customer, $server, $request, 'restart') !== null) {
            return $this->redirectToServer($server);
        }

        $job = $this->virtualServerService->restart($server);
        $this->auditLogger->log($customer, 'ts3.virtual.restart', [
            'action_id' => $job->getId(),
            'virtual_server_id' => $server->getId(),
            'node_id' => $server->getNode()->getId(),
            'sid' => $server->getSid(),
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();
        $request->getSession()->getFlashBag()->add('success', sprintf('Server neu gestartet. Action ID: %s', $job->getId()));

        return $this->redirectToServer($server);
    }

    #[Route(path: '/{id}/recreate', name: 'customer_ts3_servers_recreate', methods: ['POST'])]
    public function recreate(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($customer, $id);
        $this->validateCsrf($request, 'ts3_server_recreate_' . $id);

        $replacement = $this->virtualServerService->recreate($server);
        $request->getSession()->getFlashBag()->add('success', 'Server neu erstellt.');

        return $this->redirectToServer($replacement);
    }

    #[Route(path: '/{id}/token', name: 'customer_ts3_servers_token', methods: ['GET'])]
    public function token(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($customer, $id);

        $token = $this->tokenRepository->findOneBy(['virtualServer' => $server, 'active' => true]);
        $tokens = $this->tokenRepository->findBy(['virtualServer' => $server], ['createdAt' => 'DESC']);
        $tokenRows = array_map(function (Ts3Token $entry): array {
            return [
                'token' => $entry->getToken($this->crypto),
                'type' => $entry->getType(),
                'active' => $entry->isActive(),
                'createdAt' => $entry->getCreatedAt(),
                'revokedAt' => $entry->getRevokedAt(),
            ];
        }, $tokens);

        return new Response($this->twig->render('customer/ts3/servers/token.html.twig', [
            'activeNav' => 'ts3',
            'server' => $server,
            'token' => $token instanceof Ts3Token ? $token->getToken($this->crypto) : null,
            'tokens' => $tokenRows,
            'csrf' => $this->csrfTokens($server),
        ]));
    }

    #[Route(path: '/{id}/token/rotate', name: 'customer_ts3_servers_token_rotate', methods: ['POST'])]
    public function rotateToken(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($customer, $id);
        $this->validateCsrf($request, 'ts3_server_token_rotate_' . $id);

        if ($this->rejectIfNotReady($customer, $server, $request, 'token.rotate') !== null) {
            return $this->redirectToToken($server);
        }

        $serverGroupId = (int) $request->request->get('server_group_id', 6);
        $job = $this->virtualServerService->rotateToken($server, $serverGroupId);
        $this->auditLogger->log($customer, 'ts3.virtual.token.rotate', [
            'action_id' => $job->getId(),
            'virtual_server_id' => $server->getId(),
            'node_id' => $server->getNode()->getId(),
            'sid' => $server->getSid(),
            'server_group_id' => $serverGroupId,
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();
        $request->getSession()->getFlashBag()->add('success', sprintf('Token rotiert. Action ID: %s', $job->getId()));

        return $this->redirectToToken($server);
    }

    #[Route(path: '/{id}/viewer', name: 'customer_ts3_servers_viewer', methods: ['GET', 'POST'])]
    public function viewer(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($customer, $id);

        $viewer = $this->viewerRepository->findOneBy(['virtualServer' => $server]);
        $dto = new ViewerDto(
            $viewer?->isEnabled() ?? true,
            $viewer?->getCacheTtlMs() ?? 1500,
            $viewer?->getDomainAllowlist() ?? null,
        );

        $form = $this->formFactory->create(Ts3ViewerType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $viewer = $this->viewerService->enableViewer($server, $dto);
            $request->getSession()->getFlashBag()->add('success', 'Viewer Einstellungen gespeichert.');
        }

        return new Response($this->twig->render('customer/ts3/servers/viewer.html.twig', [
            'activeNav' => 'ts3',
            'server' => $server,
            'viewer' => $viewer,
            'form' => $form->createView(),
        ]));
    }

    #[Route(path: '/{id}/viewer/save', name: 'customer_ts3_servers_viewer_save', methods: ['POST'])]
    public function saveViewer(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($customer, $id);

        $viewer = $this->viewerRepository->findOneBy(['virtualServer' => $server]);
        $dto = new ViewerDto(
            $viewer?->isEnabled() ?? true,
            $viewer?->getCacheTtlMs() ?? 1500,
            $viewer?->getDomainAllowlist() ?? null,
        );
        $form = $this->formFactory->create(Ts3ViewerType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->viewerService->enableViewer($server, $dto);
            $request->getSession()->getFlashBag()->add('success', 'Viewer Einstellungen gespeichert.');
        }

        return $this->redirectToServer($server);
    }

    #[Route(path: '/{id}/server-groups.json', name: 'customer_ts3_servers_groups', methods: ['GET'])]
    public function serverGroups(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($customer, $id);
        $cacheKey = sprintf('ts3_server_groups_%d', $server->getId());

        $guard = $this->resolveGuardReason($server);
        if ($guard !== null) {
            $actionId = $this->rejectServerAction($customer, $server, 'servergroup.list', $guard);
            $this->entityManager->flush();
            return new JsonResponse([
                'status' => 'error',
                'message' => $guard,
                'action_id' => $actionId,
                'groups' => [],
            ]);
        }

        $payload = $this->cache->get($cacheKey, function (ItemInterface $item): array {
            $item->expiresAfter(60);
            return ['status' => 'pending', 'groups' => []];
        });

        if (!is_array($payload) || ($payload['status'] ?? 'pending') !== 'ok') {
            $limit = $this->queryLimiter->allow($cacheKey, 8, 60);
            if (!$limit->isAllowed()) {
                return new JsonResponse([
                    'status' => 'pending',
                    'groups' => [],
                    'retry_after' => $limit->getRetryAfterSeconds(),
                ]);
            }
            $job = $this->virtualServerService->queueServerGroupList($server, $cacheKey);
            $this->auditLogger->log($customer, 'ts3.virtual.servergroup.list', [
                'action_id' => $job->getId(),
                'virtual_server_id' => $server->getId(),
                'node_id' => $server->getNode()->getId(),
                'sid' => $server->getSid(),
                'job_id' => $job->getId(),
            ]);
            $this->entityManager->flush();
            return new JsonResponse(['status' => 'pending', 'groups' => [], 'action_id' => $job->getId()]);
        }

        $this->queryLimiter->reset($cacheKey);
        return new JsonResponse([
            'status' => 'ok',
            'groups' => $payload['groups'] ?? [],
        ]);
    }

    #[Route(path: '/{id}/summary.json', name: 'customer_ts3_servers_summary', methods: ['GET'])]
    public function summary(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($customer, $id);
        $cacheKey = sprintf('ts3_server_summary_%d', $server->getId());

        $guard = $this->resolveGuardReason($server);
        if ($guard !== null) {
            $actionId = $this->rejectServerAction($customer, $server, 'summary', $guard);
            $this->entityManager->flush();
            return new JsonResponse([
                'status' => 'error',
                'message' => $guard,
                'action_id' => $actionId,
            ]);
        }

        $payload = $this->cache->get($cacheKey, function (ItemInterface $item): array {
            $item->expiresAfter(30);
            return ['status' => 'pending'];
        });

        if (!is_array($payload) || ($payload['status'] ?? 'pending') !== 'ok') {
            $limit = $this->queryLimiter->allow($cacheKey, 5, 45);
            if (!$limit->isAllowed()) {
                return new JsonResponse([
                    'status' => 'pending',
                    'retry_after' => $limit->getRetryAfterSeconds(),
                ]);
            }
            $job = $this->virtualServerService->queueServerSummary($server, $cacheKey);
            $this->auditLogger->log($customer, 'ts3.virtual.summary', [
                'action_id' => $job->getId(),
                'virtual_server_id' => $server->getId(),
                'node_id' => $server->getNode()->getId(),
                'sid' => $server->getSid(),
                'job_id' => $job->getId(),
            ]);
            $this->entityManager->flush();
            return new JsonResponse(['status' => 'pending', 'action_id' => $job->getId()]);
        }

        $this->queryLimiter->reset($cacheKey);
        return new JsonResponse([
            'status' => 'ok',
            'clients_online' => $payload['clients_online'] ?? 0,
            'max_clients' => $payload['max_clients'] ?? 0,
        ]);
    }

    #[Route(path: '/{id}/bans.json', name: 'customer_ts3_servers_bans', methods: ['GET'])]
    public function bans(Request $request, int $id): JsonResponse
    {
        return $this->serverQueryResponse($request, $id, 'ts3.virtual.ban.list', 'ts3_bans_%d');
    }

    #[Route(path: '/{id}/channels.json', name: 'customer_ts3_servers_channels', methods: ['GET'])]
    public function channels(Request $request, int $id): JsonResponse
    {
        return $this->serverQueryResponse($request, $id, 'ts3.virtual.channel.list', 'ts3_channels_%d');
    }

    #[Route(path: '/{id}/clients.json', name: 'customer_ts3_servers_clients', methods: ['GET'])]
    public function clients(Request $request, int $id): JsonResponse
    {
        return $this->serverQueryResponse($request, $id, 'ts3.virtual.client.list', 'ts3_clients_%d');
    }

    #[Route(path: '/{id}/logs.json', name: 'customer_ts3_servers_logs', methods: ['GET'])]
    public function logs(Request $request, int $id): JsonResponse
    {
        return $this->serverQueryResponse($request, $id, 'ts3.virtual.log.view', 'ts3_logs_%d');
    }

    #[Route(path: '/{id}/snapshot.json', name: 'customer_ts3_servers_snapshot', methods: ['GET'])]
    public function snapshot(Request $request, int $id): JsonResponse
    {
        return $this->serverQueryResponse($request, $id, 'ts3.virtual.snapshot.create', 'ts3_snapshot_%d');
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (
            !$actor instanceof User
            || (!$actor->isAdmin() && $actor->getType() !== UserType::Customer)
        ) {
            throw new UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    private function serverQueryResponse(Request $request, int $id, string $jobType, string $cachePattern): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($customer, $id);
        $cacheKey = sprintf($cachePattern, $server->getId());

        $guard = $this->resolveGuardReason($server);
        if ($guard !== null) {
            $actionId = $this->rejectServerAction($customer, $server, $jobType, $guard);
            $this->entityManager->flush();
            return new JsonResponse([
                'status' => 'error',
                'message' => $guard,
                'action_id' => $actionId,
            ]);
        }

        $payload = $this->cache->get($cacheKey, function (ItemInterface $item): array {
            $item->expiresAfter(30);
            return ['status' => 'pending'];
        });

        if (!is_array($payload) || ($payload['status'] ?? 'pending') !== 'ok') {
            $limit = $this->queryLimiter->allow($cacheKey, 6, 60);
            if (!$limit->isAllowed()) {
                return new JsonResponse([
                    'status' => 'pending',
                    'retry_after' => $limit->getRetryAfterSeconds(),
                ]);
            }
            $job = $this->virtualServerService->queueServerQuery($server, $cacheKey, $jobType);
            $this->auditLogger->log($customer, 'ts3.virtual.query', [
                'action_id' => $job->getId(),
                'virtual_server_id' => $server->getId(),
                'node_id' => $server->getNode()->getId(),
                'sid' => $server->getSid(),
                'job_id' => $job->getId(),
                'job_type' => $jobType,
            ]);
            $this->entityManager->flush();
            return new JsonResponse(['status' => 'pending', 'action_id' => $job->getId()]);
        }

        $this->queryLimiter->reset($cacheKey);
        return new JsonResponse([
            'status' => 'ok',
            'payload' => $payload['payload'] ?? [],
        ]);
    }

    private function findServer(User $customer, int $id): Ts3VirtualServer
    {
        $criteria = [
            'id' => $id,
            'archivedAt' => null,
        ];
        if (!$customer->isAdmin()) {
            $criteria['customerId'] = $customer->getId();
        }

        $server = $this->virtualServerRepository->findOneBy($criteria);
        if ($server === null) {
            throw new NotFoundHttpException('TS3 virtual server not found.');
        }

        return $server;
    }

    private function redirectToServer(Ts3VirtualServer $server): Response
    {
        return new Response('', Response::HTTP_FOUND, [
            'Location' => $this->urlGenerator->generate('customer_ts3_servers_show', ['id' => $server->getId()]),
        ]);
    }

    private function redirectToToken(Ts3VirtualServer $server): Response
    {
        return new Response('', Response::HTTP_FOUND, [
            'Location' => $this->urlGenerator->generate('customer_ts3_servers_token', ['id' => $server->getId()]),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function csrfTokens(Ts3VirtualServer $server): array
    {
        $id = (int) $server->getId();

        return [
            'start' => $this->csrfTokenManager->getToken('ts3_server_start_' . $id)->getValue(),
            'stop' => $this->csrfTokenManager->getToken('ts3_server_stop_' . $id)->getValue(),
            'restart' => $this->csrfTokenManager->getToken('ts3_server_restart_' . $id)->getValue(),
            'recreate' => $this->csrfTokenManager->getToken('ts3_server_recreate_' . $id)->getValue(),
            'rotate' => $this->csrfTokenManager->getToken('ts3_server_token_rotate_' . $id)->getValue(),
            'settings' => $this->csrfTokenManager->getToken('ts3_server_settings_' . $id)->getValue(),
        ];
    }

    private function validateCsrf(Request $request, string $tokenId): void
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken($tokenId, $token))) {
            throw new UnauthorizedHttpException('csrf', 'Invalid CSRF token.');
        }
    }

    private function rejectIfNotReady(User $customer, Ts3VirtualServer $server, Request $request, string $action): ?string
    {
        $reason = $this->resolveGuardReason($server);
        if ($reason === null) {
            return null;
        }

        $actionId = $this->rejectServerAction($customer, $server, $action, $reason);
        $request->getSession()->getFlashBag()->add('error', sprintf('Aktion abgebrochen (%s). Action ID: %s', $reason, $actionId));
        $this->entityManager->flush();

        return $actionId;
    }

    private function rejectServerAction(User $customer, Ts3VirtualServer $server, string $action, string $reason): string
    {
        $actionId = $this->createActionId();
        $this->auditLogger->log($customer, 'ts3.virtual.rejected', [
            'action_id' => $actionId,
            'virtual_server_id' => $server->getId(),
            'node_id' => $server->getNode()->getId(),
            'sid' => $server->getSid(),
            'action' => $action,
            'reason' => $reason,
        ]);

        return $actionId;
    }

    private function resolveGuardReason(Ts3VirtualServer $server): ?string
    {
        if ($server->getSid() <= 0) {
            return 'Server-ID fehlt (Provisionierung läuft noch)';
        }

        $status = strtolower($server->getStatus());
        if (in_array($status, ['provisioning', 'deleting', 'deleted', 'error'], true)) {
            return sprintf('Serverstatus %s', $status);
        }

        return null;
    }

    private function createActionId(): string
    {
        return Uuid::v4()->toRfc4122();
    }

    private function resolveExternalHost(Ts3VirtualServer $server): string
    {
        $publicHost = $server->getPublicHost();
        if ($publicHost !== null && $publicHost !== '') {
            return $publicHost;
        }

        $node = $server->getNode();
        $agentIp = trim((string) $node->getAgent()->getLastHeartbeatIp());
        if ($agentIp !== '') {
            return $agentIp;
        }

        $agentBaseUrl = $node->getAgentBaseUrl();
        if ($agentBaseUrl !== '') {
            $host = parse_url($agentBaseUrl, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return $host;
            }
        }

        return $node->getQueryConnectIp();
    }

}
