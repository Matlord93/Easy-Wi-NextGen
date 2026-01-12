<?php

declare(strict_types=1);

namespace App\Controller\Customer;

use App\Entity\ConfigSchema;
use App\Entity\Instance;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\ConfigSchemaRepository;
use App\Repository\GameDefinitionRepository;
use App\Repository\InstanceRepository;
use App\Service\AuditLogger;
use App\Service\ConfigSchema\ConfigSchemaService;
use App\Service\SftpFileService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class CustomerInstanceConfigApiController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly GameDefinitionRepository $gameDefinitionRepository,
        private readonly ConfigSchemaRepository $configSchemaRepository,
        private readonly ConfigSchemaService $configSchemaService,
        private readonly SftpFileService $fileService,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route(path: '/api/customer/instances/{id}/configs', name: 'customer_instance_configs_api_list', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/configs', name: 'customer_instance_configs_api_list_v1', methods: ['GET'])]
    public function list(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);

        $gameDefinition = $this->gameDefinitionRepository->findOneBy(['gameKey' => $instance->getTemplate()->getGameKey()]);
        if ($gameDefinition === null) {
            return new JsonResponse(['configs' => []]);
        }

        $schemas = $this->configSchemaRepository->findByGameDefinition($gameDefinition);

        return new JsonResponse([
            'configs' => array_map(fn (ConfigSchema $schema) => $this->normalizeConfigSchema($schema), $schemas),
        ]);
    }

    #[Route(path: '/api/customer/instances/{id}/configs/{configId}', name: 'customer_instance_configs_api_show', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/configs/{configId}', name: 'customer_instance_configs_api_show_v1', methods: ['GET'])]
    public function show(Request $request, int $id, string $configId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $configSchema = $this->resolveConfigSchema($instance, $configId);

        $content = $this->readConfigFile($instance, $configSchema);
        $parseResult = $this->configSchemaService->parse($configSchema, $content);

        return new JsonResponse([
            'config' => $this->normalizeConfigSchema($configSchema),
            'schema' => $this->configSchemaService->normalizeSchema($configSchema),
            'values' => $parseResult->getValues(),
            'raw' => $content,
            'warnings' => $parseResult->getWarnings(),
        ]);
    }

    #[Route(path: '/api/customer/instances/{id}/configs/{configId}/generate-save', name: 'customer_instance_configs_api_generate', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/configs/{configId}/generate-save', name: 'customer_instance_configs_api_generate_v1', methods: ['POST'])]
    public function generateSave(Request $request, int $id, string $configId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $configSchema = $this->resolveConfigSchema($instance, $configId);
        $payload = $this->parsePayload($request);

        $values = $payload['values'] ?? [];
        if (!is_array($values)) {
            throw new BadRequestHttpException('Invalid values payload.');
        }

        $content = $this->configSchemaService->generate($configSchema, $values);
        $this->writeConfigFile($instance, $configSchema, $content);

        $parseResult = $this->configSchemaService->parse($configSchema, $content);

        $this->auditLogger->log($customer, 'instance.configs.generated', [
            'instance_id' => $instance->getId(),
            'config_id' => $configSchema->getId(),
            'config_key' => $configSchema->getConfigKey(),
        ]);

        return new JsonResponse([
            'config' => $this->normalizeConfigSchema($configSchema),
            'schema' => $this->configSchemaService->normalizeSchema($configSchema),
            'values' => $parseResult->getValues(),
            'raw' => $content,
            'warnings' => $parseResult->getWarnings(),
        ]);
    }

    #[Route(path: '/api/customer/instances/{id}/configs/{configId}', name: 'customer_instance_configs_api_update', methods: ['PUT'])]
    #[Route(path: '/api/v1/customer/instances/{id}/configs/{configId}', name: 'customer_instance_configs_api_update_v1', methods: ['PUT'])]
    public function update(Request $request, int $id, string $configId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $configSchema = $this->resolveConfigSchema($instance, $configId);
        $payload = $this->parsePayload($request);

        if (!array_key_exists('content', $payload)) {
            throw new BadRequestHttpException('Missing content payload.');
        }

        $content = (string) $payload['content'];
        $this->writeConfigFile($instance, $configSchema, $content);
        $parseResult = $this->configSchemaService->parse($configSchema, $content);

        $this->auditLogger->log($customer, 'instance.configs.updated', [
            'instance_id' => $instance->getId(),
            'config_id' => $configSchema->getId(),
            'config_key' => $configSchema->getConfigKey(),
        ]);

        return new JsonResponse([
            'config' => $this->normalizeConfigSchema($configSchema),
            'schema' => $this->configSchemaService->normalizeSchema($configSchema),
            'values' => $parseResult->getValues(),
            'raw' => $content,
            'warnings' => $parseResult->getWarnings(),
        ]);
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

        $configSchema = $this->configSchemaRepository->findOneByGameAndKey($gameDefinition, $configId);
        if ($configSchema === null) {
            throw new NotFoundHttpException('Config schema not found.');
        }

        return $configSchema;
    }

    private function readConfigFile(Instance $instance, ConfigSchema $configSchema): string
    {
        [$path, $name] = $this->splitPath($configSchema->getFilePath());

        try {
            return $this->fileService->readFile($instance, $path, $name);
        } catch (\RuntimeException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        }
    }

    private function writeConfigFile(Instance $instance, ConfigSchema $configSchema, string $content): void
    {
        [$path, $name] = $this->splitPath($configSchema->getFilePath());

        try {
            $this->fileService->writeFile($instance, $path, $name, $content);
        } catch (\RuntimeException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitPath(string $filePath): array
    {
        $filePath = trim($filePath);
        $dir = dirname($filePath);
        $name = basename($filePath);

        return [$dir === '.' ? '' : $dir, $name];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeConfigSchema(ConfigSchema $schema): array
    {
        return [
            'id' => $schema->getId(),
            'key' => $schema->getConfigKey(),
            'name' => $schema->getName(),
            'format' => $schema->getFormat(),
            'file_path' => $schema->getFilePath(),
        ];
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('session', 'Unauthorized.');
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

    /**
     * @return array<string, mixed>
     */
    private function parsePayload(Request $request): array
    {
        if ($request->getContentTypeFormat() === 'json') {
            try {
                return $request->toArray();
            } catch (\JsonException $exception) {
                throw new BadRequestHttpException('Invalid JSON payload.', $exception);
            }
        }

        return $request->request->all();
    }
}
