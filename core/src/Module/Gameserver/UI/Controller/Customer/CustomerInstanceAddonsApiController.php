<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Customer;

use App\Message\InstanceActionMessage;
use App\Module\Core\Application\DiskEnforcementService;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\Application\InstanceAddonResolver;
use App\Repository\InstanceRepository;
use App\Repository\JobRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use App\Module\Core\Attribute\RequiresModule;

#[RequiresModule('game')]
final class CustomerInstanceAddonsApiController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly JobRepository $jobRepository,
        private readonly DiskEnforcementService $diskEnforcementService,
        private readonly InstanceAddonResolver $instanceAddonResolver,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(path: '/api/instances/{id}/addons', name: 'customer_instance_addons_list', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/addons', name: 'customer_instance_addons_list_v1', methods: ['GET'])]
    public function list(Request $request, int $id): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (\Throwable $exception) {
            return $this->mapException($request, $exception);
        }

        return $this->apiOk($request, [
            'addons' => $this->instanceAddonResolver->resolve($instance),
        ]);
    }

    #[Route(path: '/api/instances/{id}/addons/health', name: 'customer_instance_addons_health', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/addons/health', name: 'customer_instance_addons_health_v1', methods: ['GET'])]
    public function health(Request $request, int $id): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (\Throwable $exception) {
            return $this->mapException($request, $exception);
        }

        $addons = $this->instanceAddonResolver->resolve($instance);

        return $this->apiOk($request, [
            'supports_addons' => $addons !== [],
            'resolver_source' => 'template',
            'max_concurrent_addon_jobs' => 1,
            'notes' => $addons === [] ? 'No addons are assigned to this template.' : null,
        ]);
    }

    #[Route(path: '/api/instances/{id}/addons/{addonId}/install', name: 'customer_instance_addons_install_v2', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/addons/{addonId}/install', name: 'customer_instance_addons_install_v2_v1', methods: ['POST'])]
    public function install(Request $request, int $id, int $addonId): JsonResponse
    {
        return $this->queueAddonAction($request, $id, $addonId, 'install');
    }

    #[Route(path: '/api/instances/{id}/addons/{addonId}/update', name: 'customer_instance_addons_update_v2', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/addons/{addonId}/update', name: 'customer_instance_addons_update_v2_v1', methods: ['POST'])]
    public function update(Request $request, int $id, int $addonId): JsonResponse
    {
        return $this->queueAddonAction($request, $id, $addonId, 'update');
    }

    #[Route(path: '/api/instances/{id}/addons/{addonId}', name: 'customer_instance_addons_remove_v2', methods: ['DELETE'])]
    #[Route(path: '/api/v1/customer/instances/{id}/addons/{addonId}', name: 'customer_instance_addons_remove_v2_v1', methods: ['DELETE'])]
    public function remove(Request $request, int $id, int $addonId): JsonResponse
    {
        return $this->queueAddonAction($request, $id, $addonId, 'remove');
    }

    private function queueAddonAction(Request $request, int $id, int $addonId, string $action): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
            $payload = $request->toArray();
        } catch (\JsonException) {
            return $this->apiError($request, 'INVALID_INPUT', 'Invalid JSON payload.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $exception) {
            return $this->mapException($request, $exception);
        }

        if (!in_array($action, ['install', 'update', 'remove'], true)) {
            return $this->apiError($request, 'INVALID_INPUT', 'Invalid addon action.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $confirm = (bool) ($payload['confirm'] ?? false);
        if (($action === 'install' || $action === 'remove') && !$confirm) {
            return $this->apiError($request, 'INVALID_INPUT', 'Confirmation is required.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $addon = $this->instanceAddonResolver->findAddonForInstance($instance, $addonId);
        if ($addon === null) {
            return $this->apiError($request, 'NOT_FOUND', 'Addon not found for this instance.', JsonResponse::HTTP_NOT_FOUND);
        }

        $addons = $this->instanceAddonResolver->resolve($instance);
        $addonState = null;
        foreach ($addons as $entry) {
            if ((int) ($entry['id'] ?? 0) === $addonId) {
                $addonState = $entry;
                break;
            }
        }

        if (!is_array($addonState)) {
            return $this->apiError($request, 'NOT_FOUND', 'Addon state not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        if (($addonState['compatible'] ?? false) !== true) {
            return $this->apiError($request, 'INCOMPATIBLE', (string) ($addonState['incompatible_reason'] ?? 'Addon is incompatible.'), JsonResponse::HTTP_CONFLICT);
        }

        if ($action === 'install' && ($addonState['installed'] ?? false) === true) {
            return $this->apiError($request, 'ALREADY_INSTALLED', 'Addon is already installed.', JsonResponse::HTTP_CONFLICT);
        }

        if (in_array($action, ['update', 'remove'], true) && ($addonState['installed'] ?? false) !== true) {
            return $this->apiError($request, 'NOT_INSTALLED', 'Addon is not installed on this instance.', JsonResponse::HTTP_CONFLICT);
        }

        if ($action === 'update' && ($addonState['update_available'] ?? false) !== true) {
            return $this->apiError($request, 'CONFLICT', 'No update is available for this addon.', JsonResponse::HTTP_CONFLICT);
        }

        $activeJob = $this->jobRepository->findLatestActiveByTypesAndInstanceId([
            'instance.addon.install',
            'instance.addon.update',
            'instance.addon.remove',
        ], $instance->getId() ?? 0);
        if ($activeJob instanceof Job) {
            return $this->apiError($request, 'RATE_LIMITED', 'Another addon action is already running.', JsonResponse::HTTP_TOO_MANY_REQUESTS, [
                'active_job_id' => $activeJob->getId(),
            ]);
        }

        $lifecycleJob = $this->jobRepository->findLatestActiveByTypesAndInstanceId([
            'instance.backup.create',
            'instance.backup.restore',
            'instance.reinstall',
            'instance.start',
            'instance.stop',
            'instance.restart',
        ], $instance->getId() ?? 0);
        if ($lifecycleJob instanceof Job) {
            return $this->apiError($request, 'CONFLICT', 'Addon action blocked while lifecycle operation is running.', JsonResponse::HTTP_CONFLICT, [
                'active_job_id' => $lifecycleJob->getId(),
                'active_job_type' => $lifecycleJob->getType(),
            ]);
        }

        $blockMessage = $this->diskEnforcementService->guardInstanceAction($instance, new \DateTimeImmutable());
        if ($blockMessage !== null) {
            return $this->apiError($request, 'CONFLICT', $blockMessage, JsonResponse::HTTP_CONFLICT);
        }

        $payload = [
            'instance_id' => (string) ($instance->getId() ?? ''),
            'customer_id' => (string) $customer->getId(),
            'node_id' => $instance->getNode()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'plugin_id' => (string) ($addon->getId() ?? ''),
            'plugin_name' => $addon->getName(),
            'plugin_version' => $addon->getVersion(),
            'plugin_checksum' => $addon->getChecksum(),
            'plugin_download_url' => $addon->getDownloadUrl(),
        ] + $this->buildCs2MetamodGameInfoPatchPayload($instance, $addon, $action);

        $message = new InstanceActionMessage(sprintf('instance.addon.%s', $action), $customer->getId(), $instance->getId(), $payload);

        $result = $this->dispatch($message);
        if (!is_array($result) || !is_string($result['job_id'] ?? null)) {
            return $this->apiError($request, 'INTERNAL_ERROR', 'Unable to queue addon action.', JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->apiOk($request, [
            'job_id' => $result['job_id'],
            'job_type' => sprintf('instance.addon.%s', $action),
            'addon_id' => $addonId,
            'status' => 'queued',
            'requires_restart' => true,
        ], JsonResponse::HTTP_ACCEPTED);
    }


    /**
     * @return array<string, mixed>
     */
    private function buildCs2MetamodGameInfoPatchPayload(Instance $instance, \App\Module\Core\Domain\Entity\GamePlugin $addon, string $action): array
    {
        $gameKey = strtolower(trim($instance->getTemplate()->getGameKey()));
        $pluginName = strtolower(trim($addon->getName()));
        if ($gameKey !== 'cs2' || $action === 'remove' || !str_contains($pluginName, 'metamod')) {
            return [];
        }

        return [
            'post_install_file_patches' => [[
                'path' => 'game/csgo/gameinfo.gi',
                'mode' => 'ensure_line_between',
                'line' => 'Game	csgo/addons/metamod',
                'after' => 'Game_LowViolence	csgo_lv',
                'before' => 'Game	csgo',
                'reapply_on_update' => true,
            ]],
        ];
    }

    /** @return array<string, mixed>|null */
    private function dispatch(InstanceActionMessage $message): ?array
    {
        $envelope = $this->messageBus->dispatch($message);
        $handled = $envelope->last(HandledStamp::class);
        $result = $handled?->getResult();

        return is_array($result) ? $result : null;
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || (!$actor->isAdmin() && $actor->getType() !== UserType::Customer)) {
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

        if (!$customer->isAdmin() && $instance->getCustomer()->getId() !== $customer->getId()) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        return $instance;
    }

    private function mapException(Request $request, \Throwable $exception): JsonResponse
    {
        if ($exception instanceof AccessDeniedHttpException) {
            return $this->apiError($request, 'FORBIDDEN', $exception->getMessage() ?: 'Forbidden.', JsonResponse::HTTP_FORBIDDEN);
        }

        if ($exception instanceof UnauthorizedHttpException) {
            return $this->apiError($request, 'UNAUTHORIZED', $exception->getMessage() ?: 'Unauthorized.', JsonResponse::HTTP_UNAUTHORIZED);
        }

        if ($exception instanceof NotFoundHttpException) {
            return $this->apiError($request, 'NOT_FOUND', $exception->getMessage() ?: 'Not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->apiError($request, 'INTERNAL_ERROR', $exception->getMessage() ?: 'Request failed.', JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function apiOk(Request $request, array $data, int $status = JsonResponse::HTTP_OK): JsonResponse
    {
        return new JsonResponse([
            'ok' => true,
            'data' => $data,
            'request_id' => $this->resolveRequestId($request),
        ], $status);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function apiError(Request $request, string $errorCode, string $message, int $status, array $context = []): JsonResponse
    {
        $payload = [
            'ok' => false,
            'error_code' => $errorCode,
            'message' => $message,
            'request_id' => $this->resolveRequestId($request),
        ];
        if ($context !== []) {
            $payload['context'] = $context;
        }

        return new JsonResponse($payload, $status);
    }

    private function resolveRequestId(Request $request): string
    {
        $header = trim((string) ($request->headers->get('X-Request-ID') ?? ''));
        if ($header !== '') {
            return $header;
        }

        return trim((string) ($request->attributes->get('request_id') ?? ''));
    }
}
