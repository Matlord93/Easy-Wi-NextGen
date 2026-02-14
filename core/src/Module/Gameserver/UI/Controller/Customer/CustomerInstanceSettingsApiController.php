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
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class CustomerInstanceSettingsApiController
{
    private const int MAX_CONFIG_BYTES = 1_048_576;

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
            'configs' => $this->resolveSettingsConfigsForInstance($instance),
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

        return $this->apiOk($request, ['configs' => $this->resolveSettingsConfigsForInstance($instance)]);
    }

    #[Route(path: '/api/instances/{id}/configs/{configId}', name: 'customer_instance_configs_envelope_show', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/configs/{configId}', name: 'customer_instance_configs_envelope_show_v1', methods: ['GET'])]
    public function showConfig(Request $request, int $id, string $configId): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
            $config = $this->resolveConfigForInstance($instance, $configId);
        } catch (HttpExceptionInterface $exception) {
            return $this->mapException($request, $exception);
        }

        return $this->apiOk($request, $config);
    }

    #[Route(path: '/api/instances/{id}/configs', name: 'customer_instance_configs_envelope_create', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/configs', name: 'customer_instance_configs_envelope_create_v1', methods: ['POST'])]
    public function createConfig(Request $request, int $id): JsonResponse
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

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return $this->apiError($request, 'INVALID_INPUT', 'name is required.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $baseConfigId = trim((string) ($payload['base_config_id'] ?? ''));
        $format = strtolower(trim((string) ($payload['format'] ?? 'txt')));
        $initialContent = (string) ($payload['initial_content'] ?? '');

        $base = null;
        if ($baseConfigId !== '') {
            try {
                $base = $this->resolveConfigForInstance($instance, $baseConfigId);
            } catch (HttpExceptionInterface $exception) {
                return $this->mapException($request, $exception);
            }

            if ($initialContent === '') {
                $initialContent = (string) ($base['content'] ?? '');
            }
            if ($format === '' || $format === 'txt') {
                $format = (string) ($base['format'] ?? 'txt');
            }
        }

        if (!$this->isSupportedFormat($format)) {
            return $this->apiError($request, 'INVALID_INPUT', 'Unsupported format.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (strlen($initialContent) > self::MAX_CONFIG_BYTES) {
            return $this->apiError($request, 'INVALID_INPUT', 'Config content exceeds maximum size.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $filePath = $this->buildInstanceConfigPath($name, $format);
        if ($this->configPathExists($instance, $filePath)) {
            return $this->apiError($request, 'CONFLICT', 'Config name already exists.', JsonResponse::HTTP_CONFLICT);
        }

        $this->storeInstanceOverride($instance, $filePath, $initialContent, [
            'name' => $name,
            'format' => $format,
            'source' => 'instance',
            'scope' => 'instance',
            'editable' => true,
        ]);
        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        return $this->apiOk($request, [
            'created' => $this->resolveConfigForInstance($instance, $this->instanceConfigIdForPath($filePath)),
        ], JsonResponse::HTTP_CREATED);
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
            $config = $this->resolveConfigForInstance($instance, $configId);
        } catch (HttpExceptionInterface $exception) {
            return $this->mapException($request, $exception);
        }

        if (($config['editable'] ?? false) !== true) {
            return $this->apiError($request, 'FORBIDDEN', 'Config is not editable.', JsonResponse::HTTP_FORBIDDEN);
        }

        $content = (string) $payload['content'];
        if (strlen($content) > self::MAX_CONFIG_BYTES) {
            return $this->apiError($request, 'INVALID_INPUT', 'Config content exceeds maximum size.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->storeInstanceOverride($instance, (string) $config['file_path'], $content, [
            'name' => (string) $config['name'],
            'format' => (string) $config['format'],
            'source' => 'instance',
            'scope' => (string) $config['scope'],
            'editable' => (bool) $config['editable'],
        ]);
        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        $updated = $this->resolveConfigForInstance($instance, $configId);

        return $this->apiOk($request, [
            'updated' => true,
            'id' => $updated['id'],
            'etag' => $updated['etag'],
            'size' => $updated['size'],
        ], JsonResponse::HTTP_ACCEPTED);
    }

    #[Route(path: '/api/instances/{id}/configs/{configId}/apply', name: 'customer_instance_configs_envelope_apply', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/configs/{configId}/apply', name: 'customer_instance_configs_envelope_apply_v1', methods: ['POST'])]
    public function applyConfig(Request $request, int $id, string $configId): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
            $config = $this->resolveConfigForInstance($instance, $configId);
        } catch (HttpExceptionInterface $exception) {
            return $this->mapException($request, $exception);
        }

        $job = new Job('instance.config.apply', [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $customer->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'config_id' => (string) $config['id'],
            'config_key' => (string) ($config['config_key'] ?? $config['id']),
            'file_path' => (string) $config['file_path'],
        ]);
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $this->apiOk($request, [
            'job_id' => $job->getId(),
            'job_type' => 'instance.config.apply',
            'status' => 'queued',
            'config_id' => (string) $config['id'],
        ], JsonResponse::HTTP_ACCEPTED);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveSettingsConfigsForInstance(Instance $instance): array
    {
        $gameDefinition = $this->gameDefinitionRepository->findOneBy(['gameKey' => $instance->getTemplate()->getGameKey()]);
        $schemas = $gameDefinition === null ? [] : $this->configSchemaRepository->findByGameDefinition($gameDefinition);
        $overrides = $instance->getConfigOverrides();

        $configs = [];
        $knownPaths = [];

        foreach ($schemas as $schema) {
            if (!$schema instanceof ConfigSchema) {
                continue;
            }
            $filePath = $schema->getFilePath();
            $knownPaths[$filePath] = true;
            $override = $overrides[$filePath] ?? null;
            $exists = is_array($override);
            $updatedAt = $exists ? (string) ($override['last_updated_at'] ?? $override['updated_at'] ?? '') : $schema->getUpdatedAt()->format(DATE_ATOM);

            $configs[] = [
                'id' => (string) $schema->getId(),
                'name' => $schema->getName(),
                'config_key' => $schema->getConfigKey(),
                'file_path' => $filePath,
                'scope' => 'template',
                'format' => $schema->getFormat(),
                'editable' => true,
                'exists' => $exists,
                'source' => $exists ? 'instance' : 'template',
                'updated_at' => $updatedAt,
            ];
        }

        foreach ($overrides as $filePath => $override) {
            if (!is_string($filePath) || isset($knownPaths[$filePath]) || !is_array($override)) {
                continue;
            }

            $format = is_string($override['format'] ?? null) && $override['format'] !== ''
                ? (string) $override['format']
                : $this->inferFormatFromPath($filePath);
            $name = is_string($override['name'] ?? null) && trim((string) $override['name']) !== ''
                ? trim((string) $override['name'])
                : basename($filePath);

            $configs[] = [
                'id' => $this->instanceConfigIdForPath($filePath),
                'name' => $name,
                'config_key' => $filePath,
                'file_path' => $filePath,
                'scope' => 'instance',
                'format' => $format,
                'editable' => true,
                'exists' => true,
                'source' => 'instance',
                'updated_at' => (string) ($override['last_updated_at'] ?? $override['updated_at'] ?? ''),
            ];
        }

        usort($configs, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

        return $configs;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveConfigForInstance(Instance $instance, string $configId): array
    {
        $configs = $this->resolveSettingsConfigsForInstance($instance);
        foreach ($configs as $config) {
            if ((string) ($config['id'] ?? '') !== $configId) {
                continue;
            }

            $overrides = $instance->getConfigOverrides();
            $filePath = (string) $config['file_path'];
            $override = $overrides[$filePath] ?? null;
            $content = is_array($override) ? (string) ($override['content'] ?? '') : '';
            $etag = hash('sha256', $content);

            return [
                'id' => $config['id'],
                'name' => $config['name'],
                'scope' => $config['scope'],
                'format' => $config['format'],
                'editable' => (bool) $config['editable'],
                'exists' => (bool) $config['exists'],
                'source' => $config['source'],
                'file_path' => $filePath,
                'config_key' => $config['config_key'] ?? $filePath,
                'content' => $content,
                'etag' => $etag,
                'size' => strlen($content),
                'updated_at' => $config['updated_at'] ?? '',
            ];
        }

        throw new NotFoundHttpException('Config not found for this instance.');
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function storeInstanceOverride(Instance $instance, string $filePath, string $content, array $metadata): void
    {
        $instance->setConfigOverride($filePath, $content);
        $overrides = $instance->getConfigOverrides();
        if (!isset($overrides[$filePath]) || !is_array($overrides[$filePath])) {
            return;
        }

        foreach ($metadata as $key => $value) {
            $overrides[$filePath][$key] = $value;
        }

        $instance->setConfigOverrides($overrides);
    }

    private function configPathExists(Instance $instance, string $filePath): bool
    {
        foreach ($this->resolveSettingsConfigsForInstance($instance) as $config) {
            if (($config['file_path'] ?? null) === $filePath) {
                return true;
            }
        }

        return false;
    }

    private function buildInstanceConfigPath(string $name, string $format): string
    {
        $base = strtolower(trim($name));
        $base = preg_replace('/[^a-z0-9._-]+/i', '-', $base) ?? '';
        $base = trim($base, '-.');
        if ($base === '') {
            throw new BadRequestHttpException('Invalid config name.');
        }

        $extension = strtolower(trim($format));
        $hasExtension = str_contains($base, '.');
        $filename = $hasExtension ? $base : sprintf('%s.%s', $base, $extension);

        return 'custom/' . $filename;
    }

    private function inferFormatFromPath(string $filePath): string
    {
        $extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));

        return $this->isSupportedFormat($extension) ? $extension : 'txt';
    }

    private function isSupportedFormat(string $format): bool
    {
        return in_array(strtolower($format), ['txt', 'cfg', 'ini', 'json', 'yaml', 'yml', 'xml', 'properties', 'conf', 'env', 'log'], true);
    }

    private function instanceConfigIdForPath(string $filePath): string
    {
        return 'instance:' . rawurlencode($filePath);
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
            $exception instanceof BadRequestHttpException
                ? 'INVALID_INPUT'
                : ($exception instanceof AccessDeniedHttpException ? 'FORBIDDEN' : ($exception instanceof UnauthorizedHttpException ? 'UNAUTHORIZED' : 'NOT_FOUND')),
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
