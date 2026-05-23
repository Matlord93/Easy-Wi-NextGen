<?php

declare(strict_types=1);

namespace App\Module\Voice\UI\Controller\Customer;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Application\Ts3\Ts3ViewerService;
use App\Module\Core\Application\Ts3\Ts3VirtualServerService;
use App\Module\Core\Application\Ts6\Ts6ViewerService;
use App\Module\Core\Application\Ts6\Ts6VirtualServerService;
use App\Module\Core\Domain\Entity\Ts3VirtualServer;
use App\Module\Core\Domain\Entity\Ts6VirtualServer;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\Dto\Ts3\ViewerDto as Ts3ViewerDto;
use App\Module\Core\Dto\Ts6\ViewerDto as Ts6ViewerDto;
use App\Module\Core\UI\Api\ResponseEnvelopeFactory;
use App\Module\Teamspeak\Application\Query\ServerQueryLimiterInterface;
use App\Repository\Ts3TokenRepository;
use App\Repository\Ts3ViewerRepository;
use App\Repository\Ts3VirtualServerRepository;
use App\Repository\Ts6TokenRepository;
use App\Repository\Ts6ViewerRepository;
use App\Repository\Ts6VirtualServerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1/customer/voice/legacy')]
final class CustomerVoiceLegacyApiController
{
    public function __construct(
        private readonly Ts3VirtualServerRepository $ts3Servers,
        private readonly Ts6VirtualServerRepository $ts6Servers,
        private readonly Ts3TokenRepository $ts3Tokens,
        private readonly Ts6TokenRepository $ts6Tokens,
        private readonly Ts3ViewerRepository $ts3Viewers,
        private readonly Ts6ViewerRepository $ts6Viewers,
        private readonly Ts3VirtualServerService $ts3Service,
        private readonly Ts6VirtualServerService $ts6Service,
        private readonly Ts3ViewerService $ts3ViewerService,
        private readonly Ts6ViewerService $ts6ViewerService,
        private readonly SecretsCrypto $crypto,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly ResponseEnvelopeFactory $responseEnvelopeFactory,
        private readonly ServerQueryLimiterInterface $queryLimiter,
        private readonly CacheInterface $cache,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/{type}/{id}/detail', name: 'customer_voice_legacy_detail_v1', methods: ['GET'],
        requirements: ['type' => 'ts3|ts6', 'id' => '\d+'])]
    public function detail(Request $request, string $type, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($type, $id, (int) $customer->getId());
        if ($server === null) {
            return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
        }

        $activeToken = null;
        $viewer = null;

        if ($type === 'ts3' && $server instanceof Ts3VirtualServer) {
            $tokenEntity = $this->ts3Tokens->findOneBy(['virtualServer' => $server, 'active' => true]);
            $activeToken = $tokenEntity ? $tokenEntity->getToken($this->crypto) : null;
            $viewerEntity = $this->ts3Viewers->findOneBy(['virtualServer' => $server]);
            if ($viewerEntity !== null) {
                $viewer = $this->serializeViewer($viewerEntity);
            }
            $connect = [
                'host' => $server->getPublicHost() ?? $server->getNode()->getQueryConnectIp(),
                'port' => $server->getVoicePort(),
            ];
        } else {
            assert($server instanceof Ts6VirtualServer);
            $tokenEntity = $this->ts6Tokens->findOneBy(['virtualServer' => $server, 'active' => true]);
            $activeToken = $tokenEntity ? $tokenEntity->getToken($this->crypto) : null;
            $viewerEntity = $this->ts6Viewers->findOneBy(['virtualServer' => $server]);
            if ($viewerEntity !== null) {
                $viewer = $this->serializeViewer($viewerEntity);
            }
            $connect = [
                'host' => $server->getPublicHost() ?? $server->getNode()->getQueryConnectIp(),
                'port' => $server->getVoicePort(),
            ];
        }

        return $this->responseEnvelopeFactory->success($request, null, 'OK', 200, [
            'instance' => $this->normalizeServer($server, $type),
            'connect' => $connect,
            'active_token' => $activeToken,
            'viewer' => $viewer,
        ]);
    }

    #[Route('/{type}/{id}/probe', name: 'customer_voice_legacy_probe_v1', methods: ['POST'],
        requirements: ['type' => 'ts3|ts6', 'id' => '\d+'])]
    public function probe(Request $request, string $type, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($type, $id, (int) $customer->getId());
        if ($server === null) {
            return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
        }

        return new JsonResponse(['status' => 'ok', 'message' => $this->translator->trans('voice_legacy_probe_not_supported')]);
    }

    #[Route('/{type}/{id}/actions/{action}', name: 'customer_voice_legacy_action_v1', methods: ['POST'],
        requirements: ['type' => 'ts3|ts6', 'id' => '\d+'])]
    public function action(Request $request, string $type, int $id, string $action): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($type, $id, (int) $customer->getId());
        if ($server === null) {
            return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
        }

        $action = strtolower(trim($action));
        if (!in_array($action, ['start', 'stop', 'restart'], true)) {
            return $this->responseEnvelopeFactory->error($request, 'Invalid action.', 'voice_action_invalid', 400);
        }

        if ($type === 'ts3') {
            assert($server instanceof Ts3VirtualServer);
            $job = match ($action) {
                'start' => $this->ts3Service->start($server),
                'stop' => $this->ts3Service->stop($server),
                'restart' => $this->ts3Service->restart($server),
            };
            $this->auditLogger->log($customer, 'voice.legacy.ts3.' . $action, ['server_id' => $id, 'job_id' => $job->getId()]);
        } else {
            assert($server instanceof Ts6VirtualServer);
            $job = match ($action) {
                'start' => $this->ts6Service->start($server),
                'stop' => $this->ts6Service->stop($server),
                'restart' => $this->ts6Service->restart($server),
            };
            $this->auditLogger->log($customer, 'voice.legacy.ts6.' . $action, ['server_id' => $id, 'job_id' => $job->getId()]);
        }

        $this->entityManager->flush();
        return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Action queued.');
    }

    #[Route('/{type}/{id}/tokens', name: 'customer_voice_legacy_tokens_v1', methods: ['GET'],
        requirements: ['type' => 'ts3|ts6', 'id' => '\d+'])]
    public function tokens(Request $request, string $type, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($type, $id, (int) $customer->getId());
        if ($server === null) {
            return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
        }

        $tokens = [];
        if ($type === 'ts3' && $server instanceof Ts3VirtualServer) {
            foreach ($this->ts3Tokens->findBy(['virtualServer' => $server], ['createdAt' => 'DESC']) as $t) {
                $tokens[] = [
                    'token' => $t->getToken($this->crypto),
                    'type' => $t->getType(),
                    'active' => $t->isActive(),
                    'created_at' => $t->getCreatedAt()->format(DATE_RFC3339),
                    'revoked_at' => $t->getRevokedAt()?->format(DATE_RFC3339),
                ];
            }
        } elseif ($type === 'ts6' && $server instanceof Ts6VirtualServer) {
            foreach ($this->ts6Tokens->findBy(['virtualServer' => $server], ['createdAt' => 'DESC']) as $t) {
                $tokens[] = [
                    'token' => $t->getToken($this->crypto),
                    'type' => $t->getType(),
                    'active' => $t->isActive(),
                    'created_at' => $t->getCreatedAt()->format(DATE_RFC3339),
                    'revoked_at' => $t->getRevokedAt()?->format(DATE_RFC3339),
                ];
            }
        }

        return $this->responseEnvelopeFactory->success($request, null, 'OK', 200, ['tokens' => $tokens]);
    }

    #[Route('/{type}/{id}/tokens/rotate', name: 'customer_voice_legacy_tokens_rotate_v1', methods: ['POST'],
        requirements: ['type' => 'ts3|ts6', 'id' => '\d+'])]
    public function rotateToken(Request $request, string $type, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($type, $id, (int) $customer->getId());
        if ($server === null) {
            return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
        }

        $body = json_decode((string) $request->getContent(), true) ?? [];
        $serverGroupId = max(1, (int) ($body['server_group_id'] ?? 6));

        if ($type === 'ts3') {
            assert($server instanceof Ts3VirtualServer);
            $job = $this->ts3Service->rotateToken($server, $serverGroupId);
            $this->auditLogger->log($customer, 'voice.legacy.ts3.token.rotate', ['server_id' => $id, 'job_id' => $job->getId()]);
        } else {
            assert($server instanceof Ts6VirtualServer);
            $job = $this->ts6Service->rotateToken($server, $serverGroupId);
            $this->auditLogger->log($customer, 'voice.legacy.ts6.token.rotate', ['server_id' => $id, 'job_id' => $job->getId()]);
        }

        $this->entityManager->flush();
        return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Token rotation queued.');
    }

    #[Route('/{type}/{id}/summary', name: 'customer_voice_legacy_summary_v1', methods: ['GET'],
        requirements: ['type' => 'ts3|ts6', 'id' => '\d+'])]
    public function summary(Request $request, string $type, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($type, $id, (int) $customer->getId());
        if ($server === null) {
            return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
        }

        $cacheKey = sprintf('legacy_%s_%d_summary', $type, $id);

        $payload = $this->cache->get($cacheKey, static function (ItemInterface $item): array {
            $item->expiresAfter(30);
            return ['status' => 'pending'];
        });

        if (is_array($payload) && ($payload['status'] ?? 'pending') === 'ok') {
            $this->queryLimiter->reset($cacheKey);
            return new JsonResponse([
                'status' => 'ok',
                'clients_online' => $payload['clients_online'] ?? 0,
                'max_clients' => $payload['max_clients'] ?? 0,
            ]);
        }

        $limit = $this->queryLimiter->allow($cacheKey, 5, 45);
        if (!$limit->isAllowed()) {
            return new JsonResponse(['status' => 'pending', 'retry_after' => $limit->getRetryAfterSeconds()]);
        }

        if ($type === 'ts3') {
            assert($server instanceof Ts3VirtualServer);
            $job = $this->ts3Service->queueServerSummary($server, $cacheKey);
            $this->auditLogger->log($customer, 'voice.legacy.ts3.summary', ['server_id' => $id, 'job_id' => $job->getId()]);
        } else {
            assert($server instanceof Ts6VirtualServer);
            $job = $this->ts6Service->queueServerSummary($server, $cacheKey);
            $this->auditLogger->log($customer, 'voice.legacy.ts6.summary', ['server_id' => $id, 'job_id' => $job->getId()]);
        }

        $this->entityManager->flush();
        return new JsonResponse(['status' => 'pending', 'action_id' => $job->getId()]);
    }

    #[Route('/{type}/{id}/groups', name: 'customer_voice_legacy_groups_v1', methods: ['GET'],
        requirements: ['type' => 'ts3|ts6', 'id' => '\d+'])]
    public function groups(Request $request, string $type, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($type, $id, (int) $customer->getId());
        if ($server === null) {
            return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
        }

        $cacheKey = sprintf('legacy_%s_%d_groups', $type, $id);

        $payload = $this->cache->get($cacheKey, static function (ItemInterface $item): array {
            $item->expiresAfter(60);
            return ['status' => 'pending', 'groups' => []];
        });

        if (is_array($payload) && ($payload['status'] ?? 'pending') === 'ok') {
            $this->queryLimiter->reset($cacheKey);
            return new JsonResponse(['status' => 'ok', 'groups' => $payload['groups'] ?? []]);
        }

        $limit = $this->queryLimiter->allow($cacheKey, 8, 60);
        if (!$limit->isAllowed()) {
            return new JsonResponse(['status' => 'pending', 'groups' => [], 'retry_after' => $limit->getRetryAfterSeconds()]);
        }

        if ($type === 'ts3') {
            assert($server instanceof Ts3VirtualServer);
            $job = $this->ts3Service->queueServerGroupList($server, $cacheKey);
            $this->auditLogger->log($customer, 'voice.legacy.ts3.groups', ['server_id' => $id, 'job_id' => $job->getId()]);
        } else {
            assert($server instanceof Ts6VirtualServer);
            $job = $this->ts6Service->queueServerGroupList($server, $cacheKey);
            $this->auditLogger->log($customer, 'voice.legacy.ts6.groups', ['server_id' => $id, 'job_id' => $job->getId()]);
        }

        $this->entityManager->flush();
        return new JsonResponse(['status' => 'pending', 'groups' => [], 'action_id' => $job->getId()]);
    }

    #[Route('/{type}/{id}/query/{queryType}', name: 'customer_voice_legacy_query_v1', methods: ['GET'],
        requirements: ['type' => 'ts3|ts6', 'id' => '\d+', 'queryType' => 'bans|channels|clients|logs'])]
    public function query(Request $request, string $type, int $id, string $queryType): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($type, $id, (int) $customer->getId());
        if ($server === null) {
            return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
        }

        $cacheKey = sprintf('legacy_%s_%d_%s', $type, $id, $queryType);

        $jobTypeMap = [
            'bans' => ['ts3' => 'ts3.virtual.ban.list', 'ts6' => 'ts6.virtual.ban.list'],
            'channels' => ['ts3' => 'ts3.virtual.channel.list', 'ts6' => 'ts6.virtual.channel.list'],
            'clients' => ['ts3' => 'ts3.virtual.client.list', 'ts6' => 'ts6.virtual.client.list'],
            'logs' => ['ts3' => 'ts3.virtual.log.view', 'ts6' => 'ts6.virtual.log.view'],
        ];

        $jobType = $jobTypeMap[$queryType][$type] ?? null;
        if ($jobType === null) {
            return $this->responseEnvelopeFactory->error($request, 'Unsupported query type.', 'voice_provider_unsupported', 400);
        }

        $payload = $this->cache->get($cacheKey, static function (ItemInterface $item): array {
            $item->expiresAfter(30);
            return ['status' => 'pending'];
        });

        if (is_array($payload) && ($payload['status'] ?? 'pending') === 'ok') {
            $this->queryLimiter->reset($cacheKey);
            return new JsonResponse(['status' => 'ok', 'payload' => $payload['payload'] ?? []]);
        }

        $limit = $this->queryLimiter->allow($cacheKey, 6, 60);
        if (!$limit->isAllowed()) {
            return new JsonResponse(['status' => 'pending', 'retry_after' => $limit->getRetryAfterSeconds()]);
        }

        if ($type === 'ts3') {
            assert($server instanceof Ts3VirtualServer);
            $job = $this->ts3Service->queueServerQuery($server, $cacheKey, $jobType);
            $this->auditLogger->log($customer, 'voice.legacy.ts3.query', ['server_id' => $id, 'type' => $queryType, 'job_id' => $job->getId()]);
        } else {
            assert($server instanceof Ts6VirtualServer);
            $job = $this->ts6Service->queueServerQuery($server, $cacheKey, $jobType);
            $this->auditLogger->log($customer, 'voice.legacy.ts6.query', ['server_id' => $id, 'type' => $queryType, 'job_id' => $job->getId()]);
        }

        $this->entityManager->flush();
        return new JsonResponse(['status' => 'pending', 'action_id' => $job->getId()]);
    }

    #[Route('/{type}/{id}/settings', name: 'customer_voice_legacy_settings_v1', methods: ['PUT'],
        requirements: ['type' => 'ts3|ts6', 'id' => '\d+'])]
    public function saveSettings(Request $request, string $type, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($type, $id, (int) $customer->getId());
        if ($server === null) {
            return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
        }

        $body = json_decode((string) $request->getContent(), true) ?? [];
        $publicHost = isset($body['public_host']) ? trim((string) $body['public_host']) : null;
        $publicHost = $publicHost !== '' ? $publicHost : null;

        $server->setPublicHost($publicHost);
        $this->entityManager->flush();
        $this->auditLogger->log($customer, 'voice.legacy.' . $type . '.settings.save', ['server_id' => $id, 'public_host' => $publicHost]);

        return $this->responseEnvelopeFactory->success($request, null, 'Settings saved.', 200, ['public_host' => $server->getPublicHost()]);
    }

    #[Route('/{type}/{id}/viewer', name: 'customer_voice_legacy_viewer_get_v1', methods: ['GET'],
        requirements: ['type' => 'ts3|ts6', 'id' => '\d+'])]
    public function getViewer(Request $request, string $type, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($type, $id, (int) $customer->getId());
        if ($server === null) {
            return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
        }

        if ($type === 'ts3' && $server instanceof Ts3VirtualServer) {
            $viewer = $this->ts3Viewers->findOneBy(['virtualServer' => $server]);
        } else {
            assert($server instanceof Ts6VirtualServer);
            $viewer = $this->ts6Viewers->findOneBy(['virtualServer' => $server]);
        }

        return $this->responseEnvelopeFactory->success($request, null, 'OK', 200, [
            'viewer' => $viewer ? $this->serializeViewer($viewer) : null,
        ]);
    }

    #[Route('/{type}/{id}/viewer', name: 'customer_voice_legacy_viewer_save_v1', methods: ['PUT'],
        requirements: ['type' => 'ts3|ts6', 'id' => '\d+'])]
    public function saveViewer(Request $request, string $type, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($type, $id, (int) $customer->getId());
        if ($server === null) {
            return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
        }

        $body = json_decode((string) $request->getContent(), true) ?? [];
        $enabled = (bool) ($body['enabled'] ?? true);
        $cacheTtlMs = max(500, (int) ($body['cache_ttl_ms'] ?? 1500));
        $domainAllowlist = isset($body['domain_allowlist']) ? trim((string) $body['domain_allowlist']) : null;
        $domainAllowlist = $domainAllowlist !== '' ? $domainAllowlist : null;

        if ($type === 'ts3') {
            assert($server instanceof Ts3VirtualServer);
            $dto = new Ts3ViewerDto($enabled, $cacheTtlMs, $domainAllowlist);
            $viewer = $this->ts3ViewerService->enableViewer($server, $dto);
            $this->auditLogger->log($customer, 'voice.legacy.ts3.viewer.save', ['server_id' => $id]);
        } else {
            assert($server instanceof Ts6VirtualServer);
            $dto = new Ts6ViewerDto($enabled, $cacheTtlMs, $domainAllowlist);
            $viewer = $this->ts6ViewerService->enableViewer($server, $dto);
            $this->auditLogger->log($customer, 'voice.legacy.ts6.viewer.save', ['server_id' => $id]);
        }

        return $this->responseEnvelopeFactory->success($request, null, 'Viewer settings saved.', 200, [
            'viewer' => $this->serializeViewer($viewer),
        ]);
    }

    #[Route('/{type}/{id}/recreate', name: 'customer_voice_legacy_recreate_v1', methods: ['POST'],
        requirements: ['type' => 'ts3|ts6', 'id' => '\d+'])]
    public function recreate(Request $request, string $type, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($type, $id, (int) $customer->getId());
        if ($server === null) {
            return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
        }

        if ($type === 'ts3') {
            assert($server instanceof Ts3VirtualServer);
            $this->ts3Service->recreate($server);
            $this->auditLogger->log($customer, 'voice.legacy.ts3.recreate', ['server_id' => $id]);
        } else {
            assert($server instanceof Ts6VirtualServer);
            $this->ts6Service->recreate($server);
            $this->auditLogger->log($customer, 'voice.legacy.ts6.recreate', ['server_id' => $id]);
        }

        $this->entityManager->flush();
        return $this->responseEnvelopeFactory->success($request, null, 'Server recreation initiated.', 202);
    }

    #[Route('/{type}/{id}/snapshot', name: 'customer_voice_legacy_snapshot_v1', methods: ['POST'],
        requirements: ['type' => 'ts3|ts6', 'id' => '\d+'])]
    public function snapshot(Request $request, string $type, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($type, $id, (int) $customer->getId());
        if ($server === null) {
            return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
        }

        $cacheKey = sprintf('legacy_%s_%d_snapshot_%d', $type, $id, time());

        if ($type === 'ts3') {
            assert($server instanceof Ts3VirtualServer);
            $job = $this->ts3Service->queueSnapshot($server, $cacheKey);
            $this->auditLogger->log($customer, 'voice.legacy.ts3.snapshot', ['server_id' => $id, 'job_id' => $job->getId()]);
        } else {
            assert($server instanceof Ts6VirtualServer);
            $job = $this->ts6Service->queueSnapshot($server, $cacheKey);
            $this->auditLogger->log($customer, 'voice.legacy.ts6.snapshot', ['server_id' => $id, 'job_id' => $job->getId()]);
        }

        return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Snapshot queued.', 202, ['cache_key' => $cacheKey]);
    }

    #[Route('/{type}/{id}/snapshot/poll', name: 'customer_voice_legacy_snapshot_poll_v1', methods: ['GET'],
        requirements: ['type' => 'ts3|ts6', 'id' => '\d+'])]
    public function snapshotPoll(Request $request, string $type, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($type, $id, (int) $customer->getId());
        if ($server === null) {
            return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
        }

        $cacheKey = trim((string) $request->query->get('key', ''));
        if ($cacheKey === '') {
            return $this->responseEnvelopeFactory->error($request, 'Missing cache key.', 'voice_snapshot_key_missing', 400);
        }

        $expectedPrefix = sprintf('legacy_%s_%d_snapshot_', $type, $id);
        if (!str_starts_with($cacheKey, $expectedPrefix)) {
            return $this->responseEnvelopeFactory->error($request, 'Invalid cache key.', 'voice_snapshot_key_invalid', 400);
        }

        $payload = $this->cache->get($cacheKey, static function (ItemInterface $item): array {
            $item->expiresAfter(30);
            return ['status' => 'pending'];
        });

        $status = is_array($payload) ? ($payload['status'] ?? 'pending') : 'pending';
        if ($status !== 'ok') {
            return new JsonResponse(['status' => 'pending']);
        }

        $snapshotContent = $payload['payload']['snapshot'] ?? null;
        if (!is_string($snapshotContent) || $snapshotContent === '') {
            return $this->responseEnvelopeFactory->error($request, 'Snapshot content empty.', 'voice_snapshot_empty', 500);
        }

        return new JsonResponse(['status' => 'ok', 'snapshot' => $snapshotContent]);
    }

    #[Route('/{type}/{id}/snapshot/restore', name: 'customer_voice_legacy_snapshot_restore_v1', methods: ['POST'],
        requirements: ['type' => 'ts3|ts6', 'id' => '\d+'])]
    public function snapshotRestore(Request $request, string $type, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($type, $id, (int) $customer->getId());
        if ($server === null) {
            return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
        }

        $uploadedFile = $request->files->get('snapshot');
        $snapshotContent = null;

        if ($uploadedFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            $content = file_get_contents($uploadedFile->getPathname());
            if ($content !== false && $content !== '') {
                $snapshotContent = trim($content);
            }
        }

        if ($snapshotContent === null || $snapshotContent === '') {
            $body = json_decode((string) $request->getContent(), true) ?? [];
            $snapshotContent = isset($body['snapshot']) ? trim((string) $body['snapshot']) : null;
        }

        if ($snapshotContent === null || $snapshotContent === '') {
            return $this->responseEnvelopeFactory->error($request, 'No snapshot content provided.', 'voice_snapshot_content_missing', 400);
        }

        if (strlen($snapshotContent) > 10 * 1024 * 1024) {
            return $this->responseEnvelopeFactory->error($request, 'Snapshot file too large (max 10 MB).', 'voice_snapshot_too_large', 413);
        }

        if ($type === 'ts3') {
            assert($server instanceof Ts3VirtualServer);
            $job = $this->ts3Service->queueSnapshotRestore($server, $snapshotContent);
            $this->auditLogger->log($customer, 'voice.legacy.ts3.snapshot.restore', ['server_id' => $id, 'job_id' => $job->getId()]);
        } else {
            assert($server instanceof Ts6VirtualServer);
            $job = $this->ts6Service->queueSnapshotRestore($server, $snapshotContent);
            $this->auditLogger->log($customer, 'voice.legacy.ts6.snapshot.restore', ['server_id' => $id, 'job_id' => $job->getId()]);
        }

        $this->entityManager->flush();
        return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Snapshot restore queued.', 202);
    }

    #[Route('/{type}/{id}/clients/{clid}/kick', name: 'customer_voice_legacy_kick_v1', methods: ['POST'],
        requirements: ['type' => 'ts3|ts6', 'id' => '\d+', 'clid' => '\d+'])]
    public function kickClient(Request $request, string $type, int $id, int $clid): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($type, $id, (int) $customer->getId());
        if ($server === null) {
            return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
        }

        $body = json_decode((string) $request->getContent(), true) ?? [];
        $reason = trim((string) ($body['reason'] ?? ''));

        if ($type === 'ts3') {
            assert($server instanceof Ts3VirtualServer);
            $job = $this->ts3Service->kickClient($server, $clid, $reason);
            $this->auditLogger->log($customer, 'voice.legacy.ts3.client.kick', ['server_id' => $id, 'clid' => $clid, 'job_id' => $job->getId()]);
        } else {
            assert($server instanceof Ts6VirtualServer);
            $job = $this->ts6Service->kickClient($server, $clid, $reason);
            $this->auditLogger->log($customer, 'voice.legacy.ts6.client.kick', ['server_id' => $id, 'clid' => $clid, 'job_id' => $job->getId()]);
        }

        return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Kick queued.');
    }

    #[Route('/{type}/{id}/clients/{clid}/poke', name: 'customer_voice_legacy_poke_v1', methods: ['POST'],
        requirements: ['type' => 'ts3|ts6', 'id' => '\d+', 'clid' => '\d+'])]
    public function pokeClient(Request $request, string $type, int $id, int $clid): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($type, $id, (int) $customer->getId());
        if ($server === null) {
            return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
        }

        $body = json_decode((string) $request->getContent(), true) ?? [];
        $message = trim((string) ($body['message'] ?? ''));
        if ($message === '') {
            return $this->responseEnvelopeFactory->error($request, 'Message is required.', 'voice_poke_message_required', 400);
        }

        if ($type === 'ts3') {
            assert($server instanceof Ts3VirtualServer);
            $job = $this->ts3Service->pokeClient($server, $clid, $message);
            $this->auditLogger->log($customer, 'voice.legacy.ts3.client.poke', ['server_id' => $id, 'clid' => $clid, 'job_id' => $job->getId()]);
        } else {
            assert($server instanceof Ts6VirtualServer);
            $job = $this->ts6Service->pokeClient($server, $clid, $message);
            $this->auditLogger->log($customer, 'voice.legacy.ts6.client.poke', ['server_id' => $id, 'clid' => $clid, 'job_id' => $job->getId()]);
        }

        return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Poke queued.');
    }

    #[Route('/{type}/{id}/bans', name: 'customer_voice_legacy_ban_add_v1', methods: ['POST'],
        requirements: ['type' => 'ts3|ts6', 'id' => '\d+'])]
    public function addBan(Request $request, string $type, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($type, $id, (int) $customer->getId());
        if ($server === null) {
            return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
        }

        $body = json_decode((string) $request->getContent(), true) ?? [];
        $banParams = [];
        foreach (['ip', 'uid', 'name', 'time', 'banreason'] as $field) {
            if (isset($body[$field]) && (string) $body[$field] !== '') {
                $banParams[$field] = $body[$field];
            }
        }

        if ($banParams === []) {
            return $this->responseEnvelopeFactory->error($request, 'At least one ban criterion is required.', 'voice_ban_criteria_required', 400);
        }

        if ($type === 'ts3') {
            assert($server instanceof Ts3VirtualServer);
            $job = $this->ts3Service->addBan($server, $banParams);
            $this->auditLogger->log($customer, 'voice.legacy.ts3.ban.add', ['server_id' => $id, 'job_id' => $job->getId()]);
        } else {
            assert($server instanceof Ts6VirtualServer);
            $job = $this->ts6Service->addBan($server, $banParams);
            $this->auditLogger->log($customer, 'voice.legacy.ts6.ban.add', ['server_id' => $id, 'job_id' => $job->getId()]);
        }

        return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Ban queued.');
    }

    #[Route('/{type}/{id}/bans/{banid}/remove', name: 'customer_voice_legacy_ban_remove_v1', methods: ['POST'],
        requirements: ['type' => 'ts3|ts6', 'id' => '\d+', 'banid' => '\d+'])]
    public function removeBan(Request $request, string $type, int $id, int $banid): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($type, $id, (int) $customer->getId());
        if ($server === null) {
            return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
        }

        if ($type === 'ts3') {
            assert($server instanceof Ts3VirtualServer);
            $job = $this->ts3Service->removeBan($server, $banid);
            $this->auditLogger->log($customer, 'voice.legacy.ts3.ban.remove', ['server_id' => $id, 'banid' => $banid, 'job_id' => $job->getId()]);
        } else {
            assert($server instanceof Ts6VirtualServer);
            $job = $this->ts6Service->removeBan($server, $banid);
            $this->auditLogger->log($customer, 'voice.legacy.ts6.ban.remove', ['server_id' => $id, 'banid' => $banid, 'job_id' => $job->getId()]);
        }

        return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Ban removal queued.');
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new UnauthorizedHttpException('session', $this->translator->trans('error_unauthorized'));
        }

        return $actor;
    }

    private function findServer(string $type, int $id, int $customerId): Ts3VirtualServer|Ts6VirtualServer|null
    {
        if ($type === 'ts3') {
            $server = $this->ts3Servers->find($id);
            if ($server instanceof Ts3VirtualServer && $server->getCustomerId() === $customerId && $server->getArchivedAt() === null) {
                return $server;
            }
        } else {
            $server = $this->ts6Servers->find($id);
            if ($server instanceof Ts6VirtualServer && $server->getCustomerId() === $customerId && $server->getArchivedAt() === null) {
                return $server;
            }
        }

        return null;
    }

    private function normalizeServer(Ts3VirtualServer|Ts6VirtualServer $server, string $type): array
    {
        $host = $server->getPublicHost() ?? $server->getNode()->getQueryConnectIp();

        return [
            'id' => $server->getId(),
            'name' => $server->getName(),
            'provider_type' => $type,
            'status' => $server->getStatus(),
            'players_online' => null,
            'players_max' => $server instanceof Ts6VirtualServer ? $server->getSlots() : null,
            'connect' => ['host' => $host, 'port' => $server->getVoicePort()],
            'actions_enabled' => true,
        ];
    }

    private function serializeViewer(object $viewer): array
    {
        return [
            'enabled' => $viewer->isEnabled(),
            'public_id' => $viewer->getPublicId(),
            'cache_ttl_ms' => $viewer->getCacheTtlMs(),
            'domain_allowlist' => $viewer->getDomainAllowlist(),
        ];
    }
}
