<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\Application\InstanceQueryService;
use App\Module\Gameserver\Application\Query\InstanceQuerySpec;
use App\Module\Gameserver\Application\Query\InvalidInstanceQueryConfiguration;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepository;
use App\Repository\InstanceRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class CustomerInstanceQueryApiController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly PortBlockRepository $portBlockRepository,
        private readonly InstanceQueryService $instanceQueryService,
    ) {
    }

    #[Route(path: '/api/instances/{id}/query', name: 'customer_instance_query_api_v2', methods: ['GET'])]
    #[Route(path: '/api/customer/instances/{id}/query', name: 'customer_instance_query_api', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/query', name: 'customer_instance_query_api_v1', methods: ['GET'])]
    #[Route(path: '/api/instances/{id}/query/health', name: 'customer_instance_query_health_api_v2', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/query/health', name: 'customer_instance_query_health_api_v1', methods: ['GET'])]
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->apiError(
                $request,
                $exception instanceof AccessDeniedHttpException ? 'FORBIDDEN' : ($exception instanceof UnauthorizedHttpException ? 'UNAUTHORIZED' : 'NOT_FOUND'),
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
                $exception->getStatusCode(),
            );
        }

        $portBlock = $this->portBlockRepository->findByInstance($instance);

        try {
            $spec = $this->instanceQueryService->resolveQuerySpec($instance, $portBlock);
        } catch (InvalidInstanceQueryConfiguration $exception) {
            $errorCode = str_contains($exception->getMessage(), 'Query host is missing') ? 'INVALID_INSTANCE_HOST' : 'INVALID_QUERY_CONFIG';
            $message = $errorCode === 'INVALID_INSTANCE_HOST'
                ? sprintf('Cannot resolve query host for instance %d (missing bind_ip/node_ip)', $id)
                : $exception->getMessage();

            return $this->apiError($request, $errorCode, $message, JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$spec->isSupported()) {
            return $this->apiOk($request, [
                'query' => [
                    'supported' => false,
                    'type' => null,
                    'target' => null,
                ],
                'resolved_spec' => $spec->toSafeArray(),
            ]);
        }

        $snapshot = $this->instanceQueryService->getSnapshot($instance, $portBlock, true);

        $normalized = is_array($snapshot['result'] ?? null) ? $snapshot['result'] : [];
        $reported = is_array($normalized['reported'] ?? null) ? $normalized['reported'] : [];

        $debug = $this->buildDebugContext($instance, $spec, $normalized);

        $queryPayload = [
            'supported' => true,
            'type' => $spec->getType(),
            'target' => sprintf('%s:%d', (string) $spec->getHost(), (int) $spec->getPort()),
            'status' => $this->resolveQueryStatus($snapshot['status'] ?? null, $normalized['status'] ?? null, $normalized['online'] ?? null),
            'latency_ms' => isset($normalized['latency_ms']) && is_numeric($normalized['latency_ms']) ? (int) $normalized['latency_ms'] : null,
            'online' => isset($normalized['online']) ? (bool) $normalized['online'] : null,
            'players' => [
                'online' => isset($reported['players']) && is_numeric($reported['players']) ? (int) $reported['players'] : null,
                'max' => isset($reported['max_players']) && is_numeric($reported['max_players']) ? (int) $reported['max_players'] : null,
            ],
            'map' => is_string($reported['map'] ?? null) ? $reported['map'] : null,
            'version' => is_string($reported['version'] ?? null) ? $reported['version'] : null,
            'checked_at' => is_string($snapshot['checked_at'] ?? null) ? $snapshot['checked_at'] : null,
            'error' => is_string($normalized['error'] ?? null) ? $normalized['error'] : null,
            'debug' => $debug + ['request_id' => $this->resolveRequestId($request)],
        ];

        if ($queryPayload['error'] !== null) {
            $errorCode = $this->resolveQueryErrorCode((string) $queryPayload['error']);
            $errorMessage = $this->resolveQueryErrorMessage((string) $queryPayload['error'], $errorCode, $id);

            return $this->apiError($request, $errorCode, $errorMessage, JsonResponse::HTTP_OK, [
                'query' => $queryPayload,
            ]);
        }

        return $this->apiOk($request, [
            'query' => $queryPayload,
            'resolved_spec' => $spec->toSafeArray(),
        ]);
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    private function findCustomerInstance(User $customer, int $id): Instance
    {
        $instance = $this->instanceRepository->find($id);
        if ($instance === null) {
            throw new NotFoundHttpException('Instance not found.');
        }

        if ($instance->getCustomer()->getId() !== $customer->getId()) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        return $instance;
    }

    private function apiOk(Request $request, array $data, int $status = JsonResponse::HTTP_OK): JsonResponse
    {
        return new JsonResponse([
            'ok' => true,
            'data' => $data,
            'request_id' => $this->resolveRequestId($request),
        ], $status);
    }

    private function apiError(Request $request, string $errorCode, string $message, int $status, array $context = []): JsonResponse
    {
        return new JsonResponse([
            'ok' => false,
            'error_code' => $errorCode,
            'message' => $message,
            'request_id' => $this->resolveRequestId($request),
            'context' => $context,
        ], $status);
    }

    private function resolveRequestId(Request $request): string
    {
        return trim((string) ($request->headers->get('X-Request-ID') ?: $request->attributes->get('request_id') ?: ''));
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    private function buildDebugContext(Instance $instance, InstanceQuerySpec $spec, array $normalized): array
    {
        $setupVars = $instance->getSetupVars();
        $requirements = $instance->getTemplate()->getRequirements();
        $queryConfig = is_array($requirements['query'] ?? null) ? $requirements['query'] : [];

        return [
            'resolved_host' => $spec->getHost(),
            'resolved_port' => $spec->getPort(),
            'resolved_protocol' => $spec->getType(),
            'host_source' => (string) ($spec->getExtra()['resolved_host_source'] ?? 'node_ip'),
            'resolved_host_source' => (string) ($spec->getExtra()['resolved_host_source'] ?? 'node_ip'),
            'port_source' => (string) ($spec->getExtra()['resolved_port_source'] ?? 'unknown'),
            'timeout_ms' => $spec->getTimeoutMs(),
            'network_mode' => (string) ($spec->getExtra()['network_mode'] ?? 'isolated'),
            'instance_game_port' => isset($setupVars['GAME_PORT']) && is_numeric($setupVars['GAME_PORT']) ? (int) $setupVars['GAME_PORT'] : null,
            'instance_query_port' => isset($setupVars['QUERY_PORT']) && is_numeric($setupVars['QUERY_PORT']) ? (int) $setupVars['QUERY_PORT'] : null,
            'template_query_port' => isset($queryConfig['port']) && is_numeric($queryConfig['port']) ? (int) $queryConfig['port'] : null,
            'template_query_protocol' => isset($queryConfig['type']) ? (string) $queryConfig['type'] : null,
            'last_error_code' => $this->resolveQueryErrorCode((string) ($normalized['error'] ?? '')),
            'last_error_message' => is_string($normalized['error'] ?? null) ? (string) $normalized['error'] : null,
            'last_query_at' => is_string($normalized['checked_at'] ?? null) ? (string) $normalized['checked_at'] : $instance->getQueryCheckedAt()?->format(DATE_ATOM),
            'request_id' => null,
        ];
    }

    private function resolveQueryErrorCode(string $error): string
    {
        $normalized = strtolower($error);

        if (str_contains($normalized, 'timeout')) {
            return 'QUERY_TIMEOUT';
        }

        if (str_contains($normalized, 'connection refused')) {
            return 'CONNECTION_REFUSED';
        }
        if (str_contains($normalized, 'permission denied')) {
            return 'PERMISSION_DENIED';
        }
        if (str_contains($normalized, 'no such host')) {
            return 'DNS_FAILED';
        }
        if (str_contains($normalized, 'unsupported')) {
            return 'UNSUPPORTED_PROTOCOL';
        }
        if (str_contains($normalized, 'missing required values: host')) {
            return 'INVALID_INSTANCE_HOST';
        }
        if (str_contains($normalized, 'invalid input') || str_contains($normalized, 'missing required values')) {
            return 'INVALID_INPUT';
        }

        return $normalized === '' ? 'INSTANCE_OFFLINE' : 'INTERNAL_ERROR';
    }

    private function resolveQueryErrorMessage(string $error, string $errorCode, int $instanceId): string
    {
        $normalized = strtolower($error);
        if ($errorCode === 'INVALID_INSTANCE_HOST' || str_contains($normalized, 'missing required values: host')) {
            return sprintf('Cannot resolve query host for instance %d (missing bind_ip/node_ip)', $instanceId);
        }

        return $error;
    }

    private function resolveQueryStatus(mixed $snapshotStatus, mixed $resultStatus, mixed $onlineHint): string
    {
        $normalize = static function (mixed $value): ?string {
            if (!is_string($value)) {
                return null;
            }

            return match (strtolower(trim($value))) {
                'running', 'online', 'up' => 'online',
                'stopped', 'offline', 'down' => 'offline',
                'queued', 'starting', 'error', 'unknown' => strtolower(trim($value)),
                default => null,
            };
        };

        $status = $normalize($resultStatus) ?? $normalize($snapshotStatus);
        if ($status !== null) {
            return $status;
        }

        if (is_bool($onlineHint)) {
            return $onlineHint ? 'online' : 'offline';
        }

        return 'unknown';
    }
}
