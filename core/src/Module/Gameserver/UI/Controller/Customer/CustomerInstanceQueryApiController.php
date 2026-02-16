<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\Application\InstanceQueryService;
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
            return $this->apiError($request, 'INVALID_QUERY_CONFIG', $exception->getMessage(), JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
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
        $queryPayload = [
            'supported' => true,
            'type' => $spec->getType(),
            'target' => sprintf('%s:%d', (string) $spec->getHost(), (int) $spec->getPort()),
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
        ];

        if ($queryPayload['error'] !== null) {
            $errorCode = $this->resolveQueryErrorCode($queryPayload['error']);

            return $this->apiError($request, $errorCode, (string) $queryPayload['error'], JsonResponse::HTTP_OK, [
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

    private function resolveQueryErrorCode(string $error): string
    {
        $normalized = strtolower($error);

        if (str_contains($normalized, 'timeout')) {
            return 'QUERY_TIMEOUT';
        }

        if (str_contains($normalized, 'connection refused') || str_contains($normalized, 'network is unreachable')) {
            return 'QUERY_UNREACHABLE';
        }

        return 'INSTANCE_OFFLINE';
    }
}
