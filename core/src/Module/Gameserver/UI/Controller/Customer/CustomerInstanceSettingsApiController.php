<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Customer;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Domain\Entity\ConfigSchema;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\Application\InstanceSlotService;
use App\Module\Gameserver\Infrastructure\Repository\GameProfileRepository;
use App\Repository\ConfigSchemaRepository;
use App\Repository\GameDefinitionRepository;
use App\Repository\InstanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class CustomerInstanceSettingsApiController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly GameDefinitionRepository $gameDefinitionRepository,
        private readonly ConfigSchemaRepository $configSchemaRepository,
        private readonly AppSettingsService $appSettingsService,
        private readonly InstanceSlotService $instanceSlotService,
        private readonly GameProfileRepository $gameProfileRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/api/instances/{id}/settings', name: 'customer_instance_settings_api_summary', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/settings', name: 'customer_instance_settings_api_summary_v1', methods: ['GET'])]
    public function summary(Request $request, int $id): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->mapException($request, $exception);
        }

        return $this->apiOk($request, [
            'instance_id' => $instance->getId(),
            'status' => strtolower($instance->getStatus()->value),
            'slots' => [
                'current_slots' => $instance->getSlots(),
                'max_slots' => $instance->getMaxSlots(),
                'lock_slots' => $instance->isLockSlots(),
            ],
            'supports_slots' => $this->gameProfileRepository->findOneByGameKey($instance->getTemplate()->getGameKey()) !== null,
        ]);
    }

    #[Route(path: '/api/instances/{id}/settings/health', name: 'customer_instance_settings_api_health', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/settings/health', name: 'customer_instance_settings_api_health_v1', methods: ['GET'])]
    public function health(Request $request, int $id): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->mapException($request, $exception);
        }

        return $this->apiOk($request, [
            'instance_id' => $instance->getId(),
            'settings_supported' => true,
        ]);
    }

    #[Route(path: '/api/instances/{id}/configs', name: 'customer_instance_configs_envelope_list', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/configs', name: 'customer_instance_configs_envelope_list_v1', methods: ['GET'])]
    public function listConfigs(Request $request, int $id): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->mapException($request, $exception);
        }

        $gameDefinition = $this->gameDefinitionRepository->findOneBy(['gameKey' => $instance->getTemplate()->getGameKey()]);
        if ($gameDefinition === null) {
            return $this->apiOk($request, ['configs' => []]);
        }

        $configs = array_map(fn (ConfigSchema $schema): array => [
            'id' => (string) $schema->getId(),
            'config_key' => $schema->getConfigKey(),
            'file_path' => $schema->getFilePath(),
            'label' => $schema->getDisplayName(),
            'editable' => $schema->isEditable(),
        ], $this->configSchemaRepository->findByGameDefinition($gameDefinition));

        return $this->apiOk($request, ['configs' => $configs]);
    }

    #[Route(path: '/api/instances/{id}/configs/{configId}', name: 'customer_instance_configs_envelope_show', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/configs/{configId}', name: 'customer_instance_configs_envelope_show_v1', methods: ['GET'])]
    public function showConfig(Request $request, int $id, string $configId): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
            $schema = $this->resolveConfigSchema($instance, $configId);
        } catch (HttpExceptionInterface $exception) {
            return $this->mapException($request, $exception);
        }

        $overrides = $instance->getConfigOverrides();
        $raw = (string) (($overrides[$schema->getFilePath()]['content'] ?? ''));

        return $this->apiOk($request, [
            'config' => [
                'id' => (string) $schema->getId(),
                'config_key' => $schema->getConfigKey(),
                'file_path' => $schema->getFilePath(),
            ],
            'raw' => $raw,
        ]);
    }

    #[Route(path: '/api/instances/{id}/configs/{configId}', name: 'customer_instance_configs_envelope_update', methods: ['PUT'])]
    #[Route(path: '/api/v1/customer/instances/{id}/configs/{configId}', name: 'customer_instance_configs_envelope_update_v1', methods: ['PUT'])]
    public function updateConfig(Request $request, int $id, string $configId): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
            $payload = $request->toArray();
        } catch (\JsonException) {
            return $this->apiError($request, 'INVALID_INPUT', 'Invalid JSON payload.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (HttpExceptionInterface $exception) {
            return $this->mapException($request, $exception);
        }

        if (!array_key_exists('content', $payload)) {
            return $this->apiError($request, 'INVALID_INPUT', 'content is required.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $schema = $this->resolveConfigSchema($instance, $configId);
        } catch (HttpExceptionInterface $exception) {
            return $this->mapException($request, $exception);
        }

        $content = (string) $payload['content'];
        $instance->setConfigOverride($schema->getFilePath(), $content);
        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        return $this->apiOk($request, [
            'config_id' => (string) $schema->getId(),
            'updated' => true,
        ], JsonResponse::HTTP_ACCEPTED);
    }

    #[Route(path: '/api/instances/{id}/configs/{configId}/apply', name: 'customer_instance_configs_envelope_apply', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/configs/{configId}/apply', name: 'customer_instance_configs_envelope_apply_v1', methods: ['POST'])]
    public function applyConfig(Request $request, int $id, string $configId): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
            $schema = $this->resolveConfigSchema($instance, $configId);
        } catch (HttpExceptionInterface $exception) {
            return $this->mapException($request, $exception);
        }

        $job = new Job('instance.config.apply', [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $customer->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'config_id' => (string) $schema->getId(),
            'config_key' => $schema->getConfigKey(),
            'file_path' => $schema->getFilePath(),
        ]);
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $this->apiOk($request, [
            'job_id' => $job->getId(),
            'config_id' => (string) $schema->getId(),
            'status' => 'queued',
        ], JsonResponse::HTTP_ACCEPTED);
    }

    private function resolveConfigSchema(Instance $instance, string $configId): ConfigSchema
    {
        $gameDefinition = $this->gameDefinitionRepository->findOneBy(['gameKey' => $instance->getTemplate()->getGameKey()]);
        if ($gameDefinition === null) {
            throw new NotFoundHttpException('Config schema not found.');
        }

        if (ctype_digit($configId)) {
            $byId = $this->configSchemaRepository->find((int) $configId);
            if ($byId !== null && $byId->getGameDefinition()->getId() === $gameDefinition->getId()) {
                return $byId;
            }
        }

        $schema = $this->configSchemaRepository->findOneByGameAndKey($gameDefinition, $configId);
        if ($schema === null) {
            throw new NotFoundHttpException('Config schema not found.');
        }

        return $schema;
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

    private function mapException(Request $request, HttpExceptionInterface $exception): JsonResponse
    {
        return $this->apiError(
            $request,
            $exception instanceof AccessDeniedHttpException ? 'FORBIDDEN' : ($exception instanceof UnauthorizedHttpException ? 'UNAUTHORIZED' : 'NOT_FOUND'),
            $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
            $exception->getStatusCode(),
        );
    }

    private function apiOk(Request $request, array $data, int $status = JsonResponse::HTTP_OK): JsonResponse
    {
        return new JsonResponse([
            'ok' => true,
            'data' => $data,
            'request_id' => $this->resolveRequestId($request),
        ], $status);
    }

    private function apiError(Request $request, string $errorCode, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'ok' => false,
            'error_code' => $errorCode,
            'message' => $message,
            'request_id' => $this->resolveRequestId($request),
        ], $status);
    }

    private function resolveRequestId(Request $request): string
    {
        return trim((string) ($request->headers->get('X-Request-ID') ?: $request->attributes->get('request_id') ?: ''));
    }
}
