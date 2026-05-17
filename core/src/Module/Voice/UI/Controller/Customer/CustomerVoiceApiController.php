<?php

declare(strict_types=1);

namespace App\Module\Voice\UI\Controller\Customer;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Application\Ts3\Ts3ViewerService;
use App\Module\Core\Application\Ts3\Ts3VirtualServerService;
use App\Module\Core\Application\Ts6\Ts6ViewerService;
use App\Module\Core\Application\Ts6\Ts6VirtualServerService;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\Ts3VirtualServer;
use App\Module\Core\Domain\Entity\Ts6VirtualServer;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\VoiceInstance;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\Dto\Ts3\ViewerDto as Ts3ViewerDto;
use App\Module\Core\Dto\Ts6\ViewerDto as Ts6ViewerDto;
use App\Module\Core\UI\Api\ResponseEnvelopeFactory;
use App\Module\Teamspeak\Application\Query\ServerQueryLimiterInterface;
use App\Module\Voice\Application\Provider\VoiceProviderRegistry;
use App\Module\Voice\Application\VoiceProbeGuard;
use App\Repository\JobRepository;
use App\Repository\Ts3TokenRepository;
use App\Repository\Ts3ViewerRepository;
use App\Repository\Ts3VirtualServerRepository;
use App\Repository\Ts6TokenRepository;
use App\Repository\Ts6ViewerRepository;
use App\Repository\Ts6VirtualServerRepository;
use App\Repository\VoiceInstanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/api/v1/customer/voice')]
final class CustomerVoiceApiController
{
    public function __construct(
        private readonly VoiceInstanceRepository $repository,
        private readonly JobRepository $jobRepository,
        private readonly VoiceProviderRegistry $providers,
        private readonly VoiceProbeGuard $probeGuard,
        private readonly EntityManagerInterface $entityManager,
        private readonly ResponseEnvelopeFactory $responseEnvelopeFactory,
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
        private readonly ServerQueryLimiterInterface $queryLimiter,
        private readonly CacheInterface $cache,
    ) {
    }

    #[Route('', name: 'customer_voice_list_v1', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instances = $this->repository->findByCustomer($customer, 200);

        return new JsonResponse(['instances' => array_map(fn ($instance) => $this->normalize($instance), $instances)]);
    }

    #[Route('/{id}/probe', name: 'customer_voice_probe_v1', methods: ['POST'])]
    public function probe(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerVoiceInstance($customer, $id);
        if ($instance === null) {
            return $this->responseEnvelopeFactory->error($request, 'Instance not found.', 'voice_instance_not_found', 404);
        }

        $checkedAt = $instance->getCheckedAt();
        $isFresh = $checkedAt !== null && $checkedAt > new \DateTimeImmutable('-45 seconds');
        if ($isFresh) {
            return new JsonResponse([
                'job_id' => null,
                'status' => 'succeeded',
                'message' => 'Using cached status.',
                'request_id' => $this->resolveRequestId($request),
                'details' => ['cached' => true, 'instance' => $this->normalize($instance)],
            ], 200);
        }

        $guard = $this->probeGuard->allow($instance);
        if (!$guard['allowed']) {
            return $this->responseEnvelopeFactory->error(
                $request,
                (string) $guard['reason'],
                (string) $guard['error_code'],
                429,
                (int) $guard['retry_after'],
                ['job_id' => null, 'details' => ['stale' => true]],
            );
        }

        $lockHandle = $this->acquireInstanceLock('probe', (int) $instance->getId());
        if (!is_resource($lockHandle)) {
            return $this->responseEnvelopeFactory->error(
                $request,
                'Probe lock is active for this instance.',
                'voice_probe_in_progress',
                409,
                3,
                ['job_id' => null, 'details' => ['stale' => true]],
            );
        }

        try {
            $active = $this->findActiveProbeJob((string) $instance->getId());
            if ($active !== null) {
                return $this->responseEnvelopeFactory->success(
                    $request,
                    $active->getId(),
                    'Probe already queued.',
                    202,
                    ['status' => 'running', 'error_code' => 'voice_probe_in_progress', 'retry_after' => 10, 'details' => ['stale' => true]],
                );
            }

            $job = new Job('voice.probe', [
                'voice_instance_id' => (string) $instance->getId(),
                'provider_type' => $instance->getNode()->getProviderType(),
                'external_id' => $instance->getExternalId(),
                'node_id' => (string) $instance->getNode()->getId(),
                'query_host' => $instance->getNode()->getHost(),
                'query_port' => (string) $instance->getNode()->getQueryPort(),
            ]);
            $this->entityManager->persist($job);
            $this->entityManager->flush();
        } finally {
            $this->releaseInstanceLock($lockHandle);
        }

        return $this->responseEnvelopeFactory->success(
            $request,
            $job->getId(),
            'Probe queued. Returning stale status.',
            202,
            ['status' => 'pending', 'details' => ['stale' => true, 'instance' => $this->normalize($instance)]],
        );
    }

    #[Route('/{id}/actions/{action}', name: 'customer_voice_action_v1', methods: ['POST'])]
    public function action(Request $request, int $id, string $action): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerVoiceInstance($customer, $id);
        if ($instance === null) {
            return $this->responseEnvelopeFactory->error($request, 'Instance not found.', 'voice_instance_not_found', 404);
        }

        $action = strtolower(trim($action));
        if (!in_array($action, ['start', 'stop', 'restart'], true)) {
            return $this->responseEnvelopeFactory->error($request, 'Invalid action.', 'voice_action_invalid', 400);
        }

        $lockHandle = $this->acquireInstanceLock('action', (int) $instance->getId());
        if (!is_resource($lockHandle)) {
            return $this->responseEnvelopeFactory->error($request, 'Action lock is active for this instance.', 'voice_action_in_progress', 409, 3);
        }

        try {
            $activeAction = $this->findActiveActionJob((string) $instance->getId());
            if ($activeAction !== null) {
                if ($activeAction->getType() === 'voice.action.' . $action) {
                    return $this->responseEnvelopeFactory->success(
                        $request,
                        $activeAction->getId(),
                        'Action already queued.',
                        202,
                        ['status' => 'running', 'error_code' => 'voice_action_in_progress', 'retry_after' => 10],
                    );
                }

                return $this->responseEnvelopeFactory->error(
                    $request,
                    'Another action is already in progress.',
                    'voice_action_in_progress',
                    409,
                    10,
                    ['job_id' => $activeAction->getId()],
                );
            }

            try {
                $adapter = $this->providers->forType($instance->getNode()->getProviderType());
            } catch (\RuntimeException) {
                return $this->responseEnvelopeFactory->error($request, 'Unsupported voice provider.', 'voice_provider_unsupported', 400);
            }

            $validation = $adapter->performAction($instance, $action);
            if (!($validation['accepted'] ?? false)) {
                return $this->responseEnvelopeFactory->error($request, (string) ($validation['reason'] ?? 'Action rejected.'), (string) ($validation['error_code'] ?? 'voice_action_rejected'), 400);
            }

            $job = new Job('voice.action.' . $action, [
                'voice_instance_id' => (string) $instance->getId(),
                'provider_type' => $instance->getNode()->getProviderType(),
                'external_id' => $instance->getExternalId(),
                'node_id' => (string) $instance->getNode()->getId(),
            ]);
            $this->entityManager->persist($job);
            $this->entityManager->flush();

            return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Action queued.');
        } finally {
            $this->releaseInstanceLock($lockHandle);
        }
    }

    #[Route('/{id}/detail', name: 'customer_voice_detail_v1', methods: ['GET'])]
    public function detail(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerVoiceInstance($customer, $id);
        if ($instance === null) {
            return $this->responseEnvelopeFactory->error($request, 'Instance not found.', 'voice_instance_not_found', 404);
        }

        $providerType = $instance->getNode()->getProviderType();

        try {
            $adapter = $this->providers->forType($providerType);
            $connect = $adapter->getConnectInfo($instance);
        } catch (\RuntimeException) {
            $connect = ['host' => $instance->getNode()->getHost(), 'port' => null];
        }

        $viewer = null;
        $activeToken = null;

        if ($providerType === 'ts3') {
            $server = $this->ts3Servers->find((int) $instance->getExternalId());
            if ($server !== null) {
                $tokenEntity = $this->ts3Tokens->findOneBy(['virtualServer' => $server, 'active' => true]);
                $activeToken = $tokenEntity ? $tokenEntity->getToken($this->crypto) : null;
                $viewerEntity = $this->ts3Viewers->findOneBy(['virtualServer' => $server]);
                if ($viewerEntity !== null) {
                    $viewer = [
                        'enabled' => $viewerEntity->isEnabled(),
                        'public_id' => $viewerEntity->getPublicId(),
                        'cache_ttl_ms' => $viewerEntity->getCacheTtlMs(),
                        'domain_allowlist' => $viewerEntity->getDomainAllowlist(),
                    ];
                }
            }
        } elseif ($providerType === 'ts6') {
            $server = $this->ts6Servers->find((int) $instance->getExternalId());
            if ($server !== null) {
                $tokenEntity = $this->ts6Tokens->findOneBy(['virtualServer' => $server, 'active' => true]);
                $activeToken = $tokenEntity ? $tokenEntity->getToken($this->crypto) : null;
                $viewerEntity = $this->ts6Viewers->findOneBy(['virtualServer' => $server]);
                if ($viewerEntity !== null) {
                    $viewer = [
                        'enabled' => $viewerEntity->isEnabled(),
                        'public_id' => $viewerEntity->getPublicId(),
                        'cache_ttl_ms' => $viewerEntity->getCacheTtlMs(),
                        'domain_allowlist' => $viewerEntity->getDomainAllowlist(),
                    ];
                }
            }
        }

        return $this->responseEnvelopeFactory->success($request, null, 'OK', 200, [
            'instance' => $this->normalize($instance),
            'connect' => $connect,
            'active_token' => $activeToken,
            'viewer' => $viewer,
        ]);
    }

    #[Route('/{id}/tokens', name: 'customer_voice_tokens_v1', methods: ['GET'])]
    public function tokens(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerVoiceInstance($customer, $id);
        if ($instance === null) {
            return $this->responseEnvelopeFactory->error($request, 'Instance not found.', 'voice_instance_not_found', 404);
        }

        $providerType = $instance->getNode()->getProviderType();
        $tokens = [];

        if ($providerType === 'ts3') {
            $server = $this->ts3Servers->find((int) $instance->getExternalId());
            if ($server !== null) {
                $rows = $this->ts3Tokens->findBy(['virtualServer' => $server], ['createdAt' => 'DESC']);
                foreach ($rows as $t) {
                    $tokens[] = [
                        'token' => $t->getToken($this->crypto),
                        'type' => $t->getType(),
                        'active' => $t->isActive(),
                        'created_at' => $t->getCreatedAt()->format(DATE_RFC3339),
                        'revoked_at' => $t->getRevokedAt()?->format(DATE_RFC3339),
                    ];
                }
            }
        } elseif ($providerType === 'ts6') {
            $server = $this->ts6Servers->find((int) $instance->getExternalId());
            if ($server !== null) {
                $rows = $this->ts6Tokens->findBy(['virtualServer' => $server], ['createdAt' => 'DESC']);
                foreach ($rows as $t) {
                    $tokens[] = [
                        'token' => $t->getToken($this->crypto),
                        'type' => $t->getType(),
                        'active' => $t->isActive(),
                        'created_at' => $t->getCreatedAt()->format(DATE_RFC3339),
                        'revoked_at' => $t->getRevokedAt()?->format(DATE_RFC3339),
                    ];
                }
            }
        }

        return $this->responseEnvelopeFactory->success($request, null, 'OK', 200, ['tokens' => $tokens]);
    }

    #[Route('/{id}/tokens/rotate', name: 'customer_voice_tokens_rotate_v1', methods: ['POST'])]
    public function rotateToken(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerVoiceInstance($customer, $id);
        if ($instance === null) {
            return $this->responseEnvelopeFactory->error($request, 'Instance not found.', 'voice_instance_not_found', 404);
        }

        $body = json_decode((string) $request->getContent(), true) ?? [];
        $serverGroupId = max(1, (int) ($body['server_group_id'] ?? 6));

        $providerType = $instance->getNode()->getProviderType();

        if ($providerType === 'ts3') {
            $server = $this->ts3Servers->find((int) $instance->getExternalId());
            if ($server === null) {
                return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
            }
            $job = $this->ts3Service->rotateToken($server, $serverGroupId);
            $this->auditLogger->log($customer, 'voice.ts3.token.rotate', [
                'voice_instance_id' => $instance->getId(),
                'virtual_server_id' => $server->getId(),
                'server_group_id' => $serverGroupId,
                'job_id' => $job->getId(),
            ]);
            $this->entityManager->flush();
            return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Token rotation queued.');
        }

        if ($providerType === 'ts6') {
            $server = $this->ts6Servers->find((int) $instance->getExternalId());
            if ($server === null) {
                return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
            }
            $job = $this->ts6Service->rotateToken($server, $serverGroupId);
            $this->auditLogger->log($customer, 'voice.ts6.token.rotate', [
                'voice_instance_id' => $instance->getId(),
                'virtual_server_id' => $server->getId(),
                'server_group_id' => $serverGroupId,
                'job_id' => $job->getId(),
            ]);
            $this->entityManager->flush();
            return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Token rotation queued.');
        }

        return $this->responseEnvelopeFactory->error($request, 'Unsupported provider for token rotation.', 'voice_provider_unsupported', 400);
    }

    #[Route('/{id}/summary', name: 'customer_voice_summary_v1', methods: ['GET'])]
    public function summary(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerVoiceInstance($customer, $id);
        if ($instance === null) {
            return $this->responseEnvelopeFactory->error($request, 'Instance not found.', 'voice_instance_not_found', 404);
        }

        $cacheKey = sprintf('voice_%d_summary', $id);
        $providerType = $instance->getNode()->getProviderType();

        $payload = $this->cache->get($cacheKey, function (ItemInterface $item): array {
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

        if ($providerType === 'ts3') {
            $server = $this->ts3Servers->find((int) $instance->getExternalId());
            if ($server === null) {
                return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
            }
            $job = $this->ts3Service->queueServerSummary($server, $cacheKey);
            $this->auditLogger->log($customer, 'voice.ts3.summary', ['voice_instance_id' => $instance->getId(), 'job_id' => $job->getId()]);
            $this->entityManager->flush();
            return new JsonResponse(['status' => 'pending', 'action_id' => $job->getId()]);
        }

        if ($providerType === 'ts6') {
            $server = $this->ts6Servers->find((int) $instance->getExternalId());
            if ($server === null) {
                return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
            }
            $job = $this->ts6Service->queueServerSummary($server, $cacheKey);
            $this->auditLogger->log($customer, 'voice.ts6.summary', ['voice_instance_id' => $instance->getId(), 'job_id' => $job->getId()]);
            $this->entityManager->flush();
            return new JsonResponse(['status' => 'pending', 'action_id' => $job->getId()]);
        }

        return $this->responseEnvelopeFactory->error($request, 'Unsupported provider.', 'voice_provider_unsupported', 400);
    }

    #[Route('/{id}/groups', name: 'customer_voice_groups_v1', methods: ['GET'])]
    public function groups(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerVoiceInstance($customer, $id);
        if ($instance === null) {
            return $this->responseEnvelopeFactory->error($request, 'Instance not found.', 'voice_instance_not_found', 404);
        }

        $cacheKey = sprintf('voice_%d_groups', $id);
        $providerType = $instance->getNode()->getProviderType();

        $payload = $this->cache->get($cacheKey, function (ItemInterface $item): array {
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

        if ($providerType === 'ts3') {
            $server = $this->ts3Servers->find((int) $instance->getExternalId());
            if ($server === null) {
                return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
            }
            $job = $this->ts3Service->queueServerGroupList($server, $cacheKey);
            $this->auditLogger->log($customer, 'voice.ts3.groups', ['voice_instance_id' => $instance->getId(), 'job_id' => $job->getId()]);
            $this->entityManager->flush();
            return new JsonResponse(['status' => 'pending', 'groups' => [], 'action_id' => $job->getId()]);
        }

        if ($providerType === 'ts6') {
            $server = $this->ts6Servers->find((int) $instance->getExternalId());
            if ($server === null) {
                return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
            }
            $job = $this->ts6Service->queueServerGroupList($server, $cacheKey);
            $this->auditLogger->log($customer, 'voice.ts6.groups', ['voice_instance_id' => $instance->getId(), 'job_id' => $job->getId()]);
            $this->entityManager->flush();
            return new JsonResponse(['status' => 'pending', 'groups' => [], 'action_id' => $job->getId()]);
        }

        return $this->responseEnvelopeFactory->error($request, 'Unsupported provider.', 'voice_provider_unsupported', 400);
    }

    #[Route('/{id}/query/{type}', name: 'customer_voice_query_v1', methods: ['GET'],
        requirements: ['type' => 'bans|channels|clients|logs'])]
    public function query(Request $request, int $id, string $type): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerVoiceInstance($customer, $id);
        if ($instance === null) {
            return $this->responseEnvelopeFactory->error($request, 'Instance not found.', 'voice_instance_not_found', 404);
        }

        $providerType = $instance->getNode()->getProviderType();
        $cacheKey = sprintf('voice_%d_%s', $id, $type);

        $jobTypeMap = [
            'bans' => ['ts3' => 'ts3.virtual.ban.list', 'ts6' => 'ts6.virtual.ban.list'],
            'channels' => ['ts3' => 'ts3.virtual.channel.list', 'ts6' => 'ts6.virtual.channel.list'],
            'clients' => ['ts3' => 'ts3.virtual.client.list', 'ts6' => 'ts6.virtual.client.list'],
            'logs' => ['ts3' => 'ts3.virtual.log.view', 'ts6' => 'ts6.virtual.log.view'],
        ];

        $jobType = $jobTypeMap[$type][$providerType] ?? null;
        if ($jobType === null) {
            return $this->responseEnvelopeFactory->error($request, 'Unsupported provider or query type.', 'voice_provider_unsupported', 400);
        }

        $payload = $this->cache->get($cacheKey, function (ItemInterface $item): array {
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

        if ($providerType === 'ts3') {
            $server = $this->ts3Servers->find((int) $instance->getExternalId());
            if ($server === null) {
                return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
            }
            $job = $this->ts3Service->queueServerQuery($server, $cacheKey, $jobType);
            $this->auditLogger->log($customer, 'voice.ts3.query', ['voice_instance_id' => $instance->getId(), 'type' => $type, 'job_id' => $job->getId()]);
            $this->entityManager->flush();
            return new JsonResponse(['status' => 'pending', 'action_id' => $job->getId()]);
        }

        $server = $this->ts6Servers->find((int) $instance->getExternalId());
        if ($server === null) {
            return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
        }
        $job = $this->ts6Service->queueServerQuery($server, $cacheKey, $jobType);
        $this->auditLogger->log($customer, 'voice.ts6.query', ['voice_instance_id' => $instance->getId(), 'type' => $type, 'job_id' => $job->getId()]);
        $this->entityManager->flush();
        return new JsonResponse(['status' => 'pending', 'action_id' => $job->getId()]);
    }

    #[Route('/{id}/settings', name: 'customer_voice_settings_v1', methods: ['PUT'])]
    public function saveSettings(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerVoiceInstance($customer, $id);
        if ($instance === null) {
            return $this->responseEnvelopeFactory->error($request, 'Instance not found.', 'voice_instance_not_found', 404);
        }

        $body = json_decode((string) $request->getContent(), true) ?? [];
        $publicHost = isset($body['public_host']) ? trim((string) $body['public_host']) : null;
        $providerType = $instance->getNode()->getProviderType();

        if ($providerType === 'ts3') {
            $server = $this->ts3Servers->find((int) $instance->getExternalId());
            if ($server === null) {
                return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
            }
            $server->setPublicHost($publicHost !== '' ? $publicHost : null);
            $this->entityManager->flush();
            $this->auditLogger->log($customer, 'voice.ts3.settings.save', ['voice_instance_id' => $instance->getId(), 'public_host' => $publicHost]);
            return $this->responseEnvelopeFactory->success($request, null, 'Settings saved.', 200, ['public_host' => $server->getPublicHost()]);
        }

        if ($providerType === 'ts6') {
            $server = $this->ts6Servers->find((int) $instance->getExternalId());
            if ($server === null) {
                return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
            }
            $server->setPublicHost($publicHost !== '' ? $publicHost : null);
            $this->entityManager->flush();
            $this->auditLogger->log($customer, 'voice.ts6.settings.save', ['voice_instance_id' => $instance->getId(), 'public_host' => $publicHost]);
            return $this->responseEnvelopeFactory->success($request, null, 'Settings saved.', 200, ['public_host' => $server->getPublicHost()]);
        }

        return $this->responseEnvelopeFactory->error($request, 'Unsupported provider.', 'voice_provider_unsupported', 400);
    }

    #[Route('/{id}/viewer', name: 'customer_voice_viewer_v1', methods: ['GET'])]
    public function getViewer(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerVoiceInstance($customer, $id);
        if ($instance === null) {
            return $this->responseEnvelopeFactory->error($request, 'Instance not found.', 'voice_instance_not_found', 404);
        }

        $providerType = $instance->getNode()->getProviderType();

        if ($providerType === 'ts3') {
            $server = $this->ts3Servers->find((int) $instance->getExternalId());
            if ($server === null) {
                return $this->responseEnvelopeFactory->success($request, null, 'OK', 200, ['viewer' => null]);
            }
            $viewer = $this->ts3Viewers->findOneBy(['virtualServer' => $server]);
            return $this->responseEnvelopeFactory->success($request, null, 'OK', 200, [
                'viewer' => $viewer ? [
                    'enabled' => $viewer->isEnabled(),
                    'public_id' => $viewer->getPublicId(),
                    'cache_ttl_ms' => $viewer->getCacheTtlMs(),
                    'domain_allowlist' => $viewer->getDomainAllowlist(),
                ] : null,
            ]);
        }

        if ($providerType === 'ts6') {
            $server = $this->ts6Servers->find((int) $instance->getExternalId());
            if ($server === null) {
                return $this->responseEnvelopeFactory->success($request, null, 'OK', 200, ['viewer' => null]);
            }
            $viewer = $this->ts6Viewers->findOneBy(['virtualServer' => $server]);
            return $this->responseEnvelopeFactory->success($request, null, 'OK', 200, [
                'viewer' => $viewer ? [
                    'enabled' => $viewer->isEnabled(),
                    'public_id' => $viewer->getPublicId(),
                    'cache_ttl_ms' => $viewer->getCacheTtlMs(),
                    'domain_allowlist' => $viewer->getDomainAllowlist(),
                ] : null,
            ]);
        }

        return $this->responseEnvelopeFactory->error($request, 'Unsupported provider.', 'voice_provider_unsupported', 400);
    }

    #[Route('/{id}/viewer', name: 'customer_voice_viewer_save_v1', methods: ['PUT'])]
    public function saveViewer(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerVoiceInstance($customer, $id);
        if ($instance === null) {
            return $this->responseEnvelopeFactory->error($request, 'Instance not found.', 'voice_instance_not_found', 404);
        }

        $body = json_decode((string) $request->getContent(), true) ?? [];
        $enabled = (bool) ($body['enabled'] ?? true);
        $cacheTtlMs = max(500, (int) ($body['cache_ttl_ms'] ?? 1500));
        $domainAllowlist = isset($body['domain_allowlist']) ? trim((string) $body['domain_allowlist']) : null;
        $domainAllowlist = $domainAllowlist !== '' ? $domainAllowlist : null;

        $providerType = $instance->getNode()->getProviderType();

        if ($providerType === 'ts3') {
            $server = $this->ts3Servers->find((int) $instance->getExternalId());
            if ($server === null) {
                return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
            }
            $dto = new Ts3ViewerDto($enabled, $cacheTtlMs, $domainAllowlist);
            $viewer = $this->ts3ViewerService->enableViewer($server, $dto);
            $this->auditLogger->log($customer, 'voice.ts3.viewer.save', ['voice_instance_id' => $instance->getId()]);
            return $this->responseEnvelopeFactory->success($request, null, 'Viewer settings saved.', 200, [
                'viewer' => [
                    'enabled' => $viewer->isEnabled(),
                    'public_id' => $viewer->getPublicId(),
                    'cache_ttl_ms' => $viewer->getCacheTtlMs(),
                    'domain_allowlist' => $viewer->getDomainAllowlist(),
                ],
            ]);
        }

        if ($providerType === 'ts6') {
            $server = $this->ts6Servers->find((int) $instance->getExternalId());
            if ($server === null) {
                return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
            }
            $dto = new Ts6ViewerDto($enabled, $cacheTtlMs, $domainAllowlist);
            $viewer = $this->ts6ViewerService->enableViewer($server, $dto);
            $this->auditLogger->log($customer, 'voice.ts6.viewer.save', ['voice_instance_id' => $instance->getId()]);
            return $this->responseEnvelopeFactory->success($request, null, 'Viewer settings saved.', 200, [
                'viewer' => [
                    'enabled' => $viewer->isEnabled(),
                    'public_id' => $viewer->getPublicId(),
                    'cache_ttl_ms' => $viewer->getCacheTtlMs(),
                    'domain_allowlist' => $viewer->getDomainAllowlist(),
                ],
            ]);
        }

        return $this->responseEnvelopeFactory->error($request, 'Unsupported provider.', 'voice_provider_unsupported', 400);
    }

    #[Route('/{id}/recreate', name: 'customer_voice_recreate_v1', methods: ['POST'])]
    public function recreate(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerVoiceInstance($customer, $id);
        if ($instance === null) {
            return $this->responseEnvelopeFactory->error($request, 'Instance not found.', 'voice_instance_not_found', 404);
        }

        $providerType = $instance->getNode()->getProviderType();

        if ($providerType === 'ts3') {
            $server = $this->ts3Servers->find((int) $instance->getExternalId());
            if ($server === null) {
                return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
            }
            $replacement = $this->ts3Service->recreate($server);
            $instance->setExternalId((string) $replacement->getId());
            $this->entityManager->flush();
            $this->auditLogger->log($customer, 'voice.ts3.recreate', [
                'voice_instance_id' => $instance->getId(),
                'old_virtual_server_id' => $server->getId(),
                'new_virtual_server_id' => $replacement->getId(),
            ]);
            return $this->responseEnvelopeFactory->success($request, null, 'Server recreation initiated.', 202);
        }

        if ($providerType === 'ts6') {
            $server = $this->ts6Servers->find((int) $instance->getExternalId());
            if ($server === null) {
                return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
            }
            $replacement = $this->ts6Service->recreate($server);
            $instance->setExternalId((string) $replacement->getId());
            $this->entityManager->flush();
            $this->auditLogger->log($customer, 'voice.ts6.recreate', [
                'voice_instance_id' => $instance->getId(),
                'old_virtual_server_id' => $server->getId(),
                'new_virtual_server_id' => $replacement->getId(),
            ]);
            return $this->responseEnvelopeFactory->success($request, null, 'Server recreation initiated.', 202);
        }

        return $this->responseEnvelopeFactory->error($request, 'Unsupported provider.', 'voice_provider_unsupported', 400);
    }

    #[Route('/{id}/snapshot', name: 'customer_voice_snapshot_v1', methods: ['POST'])]
    public function snapshot(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerVoiceInstance($customer, $id);
        if ($instance === null) {
            return $this->responseEnvelopeFactory->error($request, 'Instance not found.', 'voice_instance_not_found', 404);
        }

        $cacheKey = sprintf('voice_%d_snapshot_%d', $id, time());
        $providerType = $instance->getNode()->getProviderType();

        if ($providerType === 'ts3') {
            $server = $this->ts3Servers->find((int) $instance->getExternalId());
            if ($server === null) {
                return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
            }
            $job = $this->ts3Service->queueSnapshot($server, $cacheKey);
            $this->auditLogger->log($customer, 'voice.ts3.snapshot', ['voice_instance_id' => $instance->getId(), 'job_id' => $job->getId()]);
            return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Snapshot queued.', 202);
        }

        if ($providerType === 'ts6') {
            $server = $this->ts6Servers->find((int) $instance->getExternalId());
            if ($server === null) {
                return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
            }
            $job = $this->ts6Service->queueSnapshot($server, $cacheKey);
            $this->auditLogger->log($customer, 'voice.ts6.snapshot', ['voice_instance_id' => $instance->getId(), 'job_id' => $job->getId()]);
            return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Snapshot queued.', 202);
        }

        return $this->responseEnvelopeFactory->error($request, 'Unsupported provider.', 'voice_provider_unsupported', 400);
    }

    #[Route('/{id}/clients/{clid}/kick', name: 'customer_voice_client_kick_v1', methods: ['POST'],
        requirements: ['clid' => '\d+'])]
    public function kickClient(Request $request, int $id, int $clid): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerVoiceInstance($customer, $id);
        if ($instance === null) {
            return $this->responseEnvelopeFactory->error($request, 'Instance not found.', 'voice_instance_not_found', 404);
        }

        $body = json_decode((string) $request->getContent(), true) ?? [];
        $reason = trim((string) ($body['reason'] ?? ''));
        $providerType = $instance->getNode()->getProviderType();

        if ($providerType === 'ts3') {
            $server = $this->ts3Servers->find((int) $instance->getExternalId());
            if ($server === null) {
                return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
            }
            $job = $this->ts3Service->kickClient($server, $clid, $reason);
            $this->auditLogger->log($customer, 'voice.ts3.client.kick', ['voice_instance_id' => $instance->getId(), 'clid' => $clid, 'job_id' => $job->getId()]);
            return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Kick queued.');
        }

        if ($providerType === 'ts6') {
            $server = $this->ts6Servers->find((int) $instance->getExternalId());
            if ($server === null) {
                return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
            }
            $job = $this->ts6Service->kickClient($server, $clid, $reason);
            $this->auditLogger->log($customer, 'voice.ts6.client.kick', ['voice_instance_id' => $instance->getId(), 'clid' => $clid, 'job_id' => $job->getId()]);
            return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Kick queued.');
        }

        return $this->responseEnvelopeFactory->error($request, 'Unsupported provider.', 'voice_provider_unsupported', 400);
    }

    #[Route('/{id}/clients/{clid}/poke', name: 'customer_voice_client_poke_v1', methods: ['POST'],
        requirements: ['clid' => '\d+'])]
    public function pokeClient(Request $request, int $id, int $clid): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerVoiceInstance($customer, $id);
        if ($instance === null) {
            return $this->responseEnvelopeFactory->error($request, 'Instance not found.', 'voice_instance_not_found', 404);
        }

        $body = json_decode((string) $request->getContent(), true) ?? [];
        $message = trim((string) ($body['message'] ?? ''));
        if ($message === '') {
            return $this->responseEnvelopeFactory->error($request, 'Message is required.', 'voice_poke_message_required', 400);
        }
        $providerType = $instance->getNode()->getProviderType();

        if ($providerType === 'ts3') {
            $server = $this->ts3Servers->find((int) $instance->getExternalId());
            if ($server === null) {
                return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
            }
            $job = $this->ts3Service->pokeClient($server, $clid, $message);
            $this->auditLogger->log($customer, 'voice.ts3.client.poke', ['voice_instance_id' => $instance->getId(), 'clid' => $clid, 'job_id' => $job->getId()]);
            return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Poke queued.');
        }

        if ($providerType === 'ts6') {
            $server = $this->ts6Servers->find((int) $instance->getExternalId());
            if ($server === null) {
                return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
            }
            $job = $this->ts6Service->pokeClient($server, $clid, $message);
            $this->auditLogger->log($customer, 'voice.ts6.client.poke', ['voice_instance_id' => $instance->getId(), 'clid' => $clid, 'job_id' => $job->getId()]);
            return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Poke queued.');
        }

        return $this->responseEnvelopeFactory->error($request, 'Unsupported provider.', 'voice_provider_unsupported', 400);
    }

    #[Route('/{id}/bans', name: 'customer_voice_ban_add_v1', methods: ['POST'])]
    public function addBan(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerVoiceInstance($customer, $id);
        if ($instance === null) {
            return $this->responseEnvelopeFactory->error($request, 'Instance not found.', 'voice_instance_not_found', 404);
        }

        $body = json_decode((string) $request->getContent(), true) ?? [];
        $banParams = [];
        foreach (['ip', 'uid', 'name', 'time', 'banreason'] as $field) {
            if (isset($body[$field]) && (string) $body[$field] !== '') {
                $banParams[$field] = $body[$field];
            }
        }

        if ($banParams === []) {
            return $this->responseEnvelopeFactory->error($request, 'At least one ban criterion (ip, uid, name) is required.', 'voice_ban_criteria_required', 400);
        }

        $providerType = $instance->getNode()->getProviderType();

        if ($providerType === 'ts3') {
            $server = $this->ts3Servers->find((int) $instance->getExternalId());
            if ($server === null) {
                return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
            }
            $job = $this->ts3Service->addBan($server, $banParams);
            $this->auditLogger->log($customer, 'voice.ts3.ban.add', ['voice_instance_id' => $instance->getId(), 'job_id' => $job->getId()]);
            return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Ban queued.');
        }

        if ($providerType === 'ts6') {
            $server = $this->ts6Servers->find((int) $instance->getExternalId());
            if ($server === null) {
                return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
            }
            $job = $this->ts6Service->addBan($server, $banParams);
            $this->auditLogger->log($customer, 'voice.ts6.ban.add', ['voice_instance_id' => $instance->getId(), 'job_id' => $job->getId()]);
            return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Ban queued.');
        }

        return $this->responseEnvelopeFactory->error($request, 'Unsupported provider.', 'voice_provider_unsupported', 400);
    }

    #[Route('/{id}/bans/{banid}/remove', name: 'customer_voice_ban_remove_v1', methods: ['POST'],
        requirements: ['banid' => '\d+'])]
    public function removeBan(Request $request, int $id, int $banid): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerVoiceInstance($customer, $id);
        if ($instance === null) {
            return $this->responseEnvelopeFactory->error($request, 'Instance not found.', 'voice_instance_not_found', 404);
        }

        $providerType = $instance->getNode()->getProviderType();

        if ($providerType === 'ts3') {
            $server = $this->ts3Servers->find((int) $instance->getExternalId());
            if ($server === null) {
                return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
            }
            $job = $this->ts3Service->removeBan($server, $banid);
            $this->auditLogger->log($customer, 'voice.ts3.ban.remove', ['voice_instance_id' => $instance->getId(), 'banid' => $banid, 'job_id' => $job->getId()]);
            return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Ban removal queued.');
        }

        if ($providerType === 'ts6') {
            $server = $this->ts6Servers->find((int) $instance->getExternalId());
            if ($server === null) {
                return $this->responseEnvelopeFactory->error($request, 'Server not found.', 'voice_server_not_found', 404);
            }
            $job = $this->ts6Service->removeBan($server, $banid);
            $this->auditLogger->log($customer, 'voice.ts6.ban.remove', ['voice_instance_id' => $instance->getId(), 'banid' => $banid, 'job_id' => $job->getId()]);
            return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Ban removal queued.');
        }

        return $this->responseEnvelopeFactory->error($request, 'Unsupported provider.', 'voice_provider_unsupported', 400);
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    private function normalize(VoiceInstance $instance): array
    {
        try {
            $adapter = $this->providers->forType($instance->getNode()->getProviderType());
            $connect = $adapter->getConnectInfo($instance);
        } catch (\RuntimeException) {
            $connect = ['host' => $instance->getNode()->getHost(), 'port' => null];
        }

        $activeProbe = $this->findActiveProbeJob((string) $instance->getId());
        $activeAction = $this->findActiveActionJob((string) $instance->getId());

        return [
            'id' => $instance->getId(),
            'name' => $instance->getName(),
            'provider_type' => $instance->getNode()->getProviderType(),
            'status' => $instance->getStatus(),
            'players_online' => $instance->getPlayersOnline(),
            'players_max' => $instance->getPlayersMax(),
            'reason' => $instance->getReason(),
            'error_code' => $instance->getErrorCode(),
            'checked_at' => $instance->getCheckedAt()?->format(DATE_RFC3339),
            'stale' => $instance->getCheckedAt() === null || $instance->getCheckedAt() < new \DateTimeImmutable('-45 seconds'),
            'probe_in_progress' => $activeProbe !== null,
            'action_in_progress' => $activeAction !== null,
            'active_job_id' => $activeAction?->getId() ?? $activeProbe?->getId(),
            'retry_after' => ($activeAction !== null || $activeProbe !== null) ? 10 : null,
            'connect' => $connect,
        ];
    }

    private function resolveRequestId(Request $request): string
    {
        $requestId = trim((string) ($request->headers->get('X-Request-ID') ?? ''));
        if ($requestId !== '') {
            return $requestId;
        }

        $traceId = trim((string) ($request->attributes->get('request_id') ?? ''));

        return $traceId !== '' ? $traceId : 'req-' . bin2hex(random_bytes(6));
    }

    private function findCustomerVoiceInstance(User $customer, int $id): ?VoiceInstance
    {
        $instance = $this->repository->find($id);
        if ($instance === null || $instance->getCustomer()->getId() !== $customer->getId()) {
            return null;
        }

        return $instance;
    }

    private function findActiveActionJob(string $voiceInstanceId): ?Job
    {
        foreach (['voice.action.start', 'voice.action.stop', 'voice.action.restart'] as $type) {
            $active = $this->jobRepository->findActiveByTypeAndPayloadField($type, 'voice_instance_id', $voiceInstanceId);
            if ($active !== null) {
                return $active;
            }
        }

        return null;
    }

    private function findActiveProbeJob(string $voiceInstanceId): ?Job
    {
        return $this->jobRepository->findActiveByTypeAndPayloadField('voice.probe', 'voice_instance_id', $voiceInstanceId);
    }

    /** @return resource|false */
    private function acquireInstanceLock(string $scope, int $instanceId)
    {
        $dir = sys_get_temp_dir() . '/easywi_voice_locks';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $path = sprintf('%s/%s_%d.lock', $dir, $scope, $instanceId);
        $handle = @fopen($path, 'c+');
        if (!is_resource($handle)) {
            return false;
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);
            return false;
        }

        return $handle;
    }

    /** @param resource|false $handle */
    private function releaseInstanceLock($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }

        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}
