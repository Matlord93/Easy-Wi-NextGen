<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Customer;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\ConfigSchema\ConfigSchemaService;
use App\Module\Core\Domain\Entity\ConfigSchema;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\JobStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\UI\Api\ResponseEnvelopeFactory;
use App\Module\Gameserver\Application\InstanceSlotService;
use App\Module\Gameserver\Infrastructure\Repository\GameProfileRepository;
use App\Repository\ConfigSchemaRepository;
use App\Repository\GameDefinitionRepository;
use App\Repository\InstanceRepository;
use App\Repository\JobRepository;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly GameProfileRepository $gameProfileRepository,
        private readonly ConfigSchemaService $configSchemaService,
        private readonly JobRepository $jobRepository,
        private readonly AuditLogger $auditLogger,
        private readonly InstanceSlotService $instanceSlotService,
        private readonly EntityManagerInterface $entityManager,
        private readonly ResponseEnvelopeFactory $responseEnvelopeFactory,
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
        $this->assertConfigEditable($instance, $configSchema);

        $jobId = trim((string) $request->query->get('jobId', ''));
        if ($jobId !== '') {
            $job = $this->findConfigJob($instance, $customer, $jobId, 'instance.files.read');

            return $this->buildConfigResponseFromJob($instance, $configSchema, $job);
        }

        $job = $this->queueReadJob($instance, $customer, $configSchema);
        $this->entityManager->flush();

        return new JsonResponse([
            'status' => 'queued',
            'job_id' => $job->getId(),
            'config' => $this->normalizeConfigSchema($configSchema),
            'schema' => $this->configSchemaService->normalizeSchema($configSchema),
        ]);
    }

    #[Route(path: '/api/customer/instances/{id}/configs/{configId}/generate-save', name: 'customer_instance_configs_api_generate', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/configs/{configId}/generate-save', name: 'customer_instance_configs_api_generate_v1', methods: ['POST'])]
    public function generateSave(Request $request, int $id, string $configId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $configSchema = $this->resolveConfigSchema($instance, $configId);
        $this->assertConfigEditable($instance, $configSchema);
        $payload = $this->parsePayload($request);

        $values = $payload['values'] ?? [];
        if (!is_array($values)) {
            throw new BadRequestHttpException('Invalid values payload.');
        }

        $slotUpdate = $this->resolveRequestedSlots($instance, $configSchema, $values);
        if ($slotUpdate !== null) {
            $values[$slotUpdate['slot_key']] = (string) $slotUpdate['slots'];
            $this->instanceSlotService->enforceSlots($instance, $slotUpdate['slots']);
            $this->entityManager->persist($instance);
        }

        $validationErrors = $this->validateValuesAgainstSchema($configSchema, $values);
        if ($validationErrors !== []) {
            return $this->responseEnvelopeFactory->error(
                $request,
                'Config values are invalid.',
                'config_validation_failed',
                JsonResponse::HTTP_BAD_REQUEST,
                null,
                ['details' => ['field_errors' => $validationErrors]],
            );
        }

        $content = $this->configSchemaService->generate($configSchema, $values);
        $instance->setConfigOverride($configSchema->getFilePath(), $content);
        $this->entityManager->persist($instance);
        $job = $this->queueWriteJob($instance, $customer, $configSchema, $content, 'instance.configs.generated_requested');
        $this->entityManager->flush();

        return new JsonResponse([
            'status' => 'queued',
            'job_id' => $job->getId(),
            'snapshot' => $this->configSnapshotMeta($instance, $configSchema),
            'apply_required' => true,
            'apply_mode' => $this->resolveApplyMode($configSchema),
        ], JsonResponse::HTTP_ACCEPTED);
    }

    #[Route(path: '/api/customer/instances/{id}/configs/{configId}', name: 'customer_instance_configs_api_update', methods: ['PUT'])]
    #[Route(path: '/api/v1/customer/instances/{id}/configs/{configId}', name: 'customer_instance_configs_api_update_v1', methods: ['PUT'])]
    public function update(Request $request, int $id, string $configId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $configSchema = $this->resolveConfigSchema($instance, $configId);
        $this->assertConfigEditable($instance, $configSchema);
        $payload = $this->parsePayload($request);

        if (!array_key_exists('content', $payload)) {
            throw new BadRequestHttpException('Missing content payload.');
        }

        $content = (string) $payload['content'];

        $parseResult = $this->configSchemaService->parse($configSchema, $content);
        $fieldErrors = $this->validateValuesAgainstSchema($configSchema, $parseResult->getValues());
        if ($fieldErrors !== []) {
            return $this->responseEnvelopeFactory->error(
                $request,
                'Config values are invalid.',
                'config_validation_failed',
                JsonResponse::HTTP_BAD_REQUEST,
                null,
                ['details' => ['field_errors' => $fieldErrors]],
            );
        }
        $slotUpdate = $this->resolveRequestedSlotsFromContent($instance, $configSchema, $content);
        if ($slotUpdate !== null) {
            $content = $this->configSchemaService->generate($configSchema, $slotUpdate['values']);
            $this->instanceSlotService->enforceSlots($instance, $slotUpdate['slots']);
            $this->entityManager->persist($instance);
        }

        $instance->setConfigOverride($configSchema->getFilePath(), $content);
        $this->entityManager->persist($instance);
        $job = $this->queueWriteJob($instance, $customer, $configSchema, $content, 'instance.configs.updated_requested');
        $this->entityManager->flush();

        return new JsonResponse([
            'status' => 'queued',
            'job_id' => $job->getId(),
            'snapshot' => $this->configSnapshotMeta($instance, $configSchema),
            'apply_required' => true,
            'apply_mode' => $this->resolveApplyMode($configSchema),
        ], JsonResponse::HTTP_ACCEPTED);
    }


    #[Route(path: '/api/customer/instances/{id}/configs/{configId}/apply', name: 'customer_instance_configs_api_apply', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/configs/{configId}/apply', name: 'customer_instance_configs_api_apply_v1', methods: ['POST'])]
    public function apply(Request $request, int $id, string $configId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $configSchema = $this->resolveConfigSchema($instance, $configId);

        $applyMode = $this->resolveApplyMode($configSchema);
        $active = $this->jobRepository->findLatestActiveByTypesAndInstanceId(['instance.config.apply', 'instance.restart'], (int) $instance->getId());
        if ($active !== null) {
            return new JsonResponse([
                'status' => 'queued',
                'job_id' => $active->getId(),
                'message' => 'Apply job already in progress.',
                'apply_mode' => $applyMode,
            ], JsonResponse::HTTP_ACCEPTED);
        }

        if ($applyMode === 'restart') {
            $job = new Job('instance.restart', [
                'instance_id' => (string) $instance->getId(),
                'customer_id' => (string) $customer->getId(),
                'agent_id' => $instance->getNode()->getId(),
                'action' => 'config_apply_restart',
                'config_id' => (string) $configSchema->getId(),
            ]);
            $this->entityManager->persist($job);
            $this->entityManager->flush();

            return new JsonResponse([
                'status' => 'queued',
                'job_id' => $job->getId(),
                'message' => 'Config apply requires restart. Restart job queued.',
                'apply_mode' => 'restart',
            ], JsonResponse::HTTP_ACCEPTED);
        }

        $job = new Job('instance.config.apply', [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $customer->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'config_id' => (string) $configSchema->getId(),
            'config_key' => $configSchema->getConfigKey(),
            'file_path' => $configSchema->getFilePath(),
            'apply_mode' => $applyMode,
        ]);
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return new JsonResponse([
            'status' => 'queued',
            'job_id' => $job->getId(),
            'message' => 'Config apply job queued.',
            'apply_mode' => $applyMode,
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

        $configSchema = $this->configSchemaRepository->findOneByGameAndKey($gameDefinition, $configId);
        if ($configSchema === null) {
            throw new NotFoundHttpException('Config schema not found.');
        }

        return $configSchema;
    }

    private function assertConfigEditable(Instance $instance, ConfigSchema $configSchema): void
    {
        if ($instance->getStatus() === InstanceStatus::Running) {
            return;
        }

        if ($this->supportsOfflineEdit($configSchema)) {
            return;
        }

        throw new BadRequestHttpException('Config edits are only available while the server is running.');
    }

    private function supportsOfflineEdit(ConfigSchema $configSchema): bool
    {
        $schema = $configSchema->getSchema();
        $offline = $schema['offline_edit'] ?? $schema['offlineEdit'] ?? false;

        return filter_var($offline, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array{slot_key: string, slots: int}|null
     */
    private function resolveRequestedSlots(Instance $instance, ConfigSchema $configSchema, array $values): ?array
    {
        $slotRule = $this->resolveSlotRule($instance, $configSchema);
        if ($slotRule === null) {
            return null;
        }

        $slotKey = $this->findSlotKey($values, $slotRule['config_key']);
        if ($slotKey === null) {
            return null;
        }

        $value = $values[$slotKey];
        if (!is_numeric($value)) {
            return null;
        }

        if ($instance->isLockSlots()) {
            throw new BadRequestHttpException('Slots are locked for this instance.');
        }

        $requested = (int) $value;
        if ($requested <= 0) {
            throw new BadRequestHttpException('slots must be positive.');
        }

        $requested = min($requested, $instance->getMaxSlots());

        return [
            'slot_key' => $slotKey,
            'slots' => $requested,
        ];
    }

    /**
     * @return array{values: array<string, mixed>, slots: int}|null
     */
    private function resolveRequestedSlotsFromContent(Instance $instance, ConfigSchema $configSchema, string $content): ?array
    {
        $slotRule = $this->resolveSlotRule($instance, $configSchema);
        if ($slotRule === null) {
            return null;
        }

        $parseResult = $this->configSchemaService->parse($configSchema, $content);
        $values = $parseResult->getValues();

        $slotUpdate = $this->resolveRequestedSlots($instance, $configSchema, $values);
        if ($slotUpdate === null) {
            return null;
        }

        $values[$slotUpdate['slot_key']] = (string) $slotUpdate['slots'];

        return [
            'values' => $values,
            'slots' => $slotUpdate['slots'],
        ];
    }

    /**
     * @return array{config_key: string, config_file: string}|null
     */
    private function resolveSlotRule(Instance $instance, ConfigSchema $configSchema): ?array
    {
        $profile = $this->gameProfileRepository->findOneByGameKey($instance->getTemplate()->getGameKey());
        if ($profile === null) {
            return null;
        }

        $slotRules = $profile->getSlotRules();
        if (($slotRules['mode'] ?? null) !== 'config') {
            return null;
        }

        $configKey = trim((string) ($slotRules['config_key'] ?? ''));
        $configFile = trim((string) ($slotRules['config_file'] ?? ''));
        if ($configKey === '' || $configFile === '') {
            return null;
        }

        $schemaFile = strtolower(basename($configSchema->getFilePath()));
        if ($schemaFile !== strtolower($configFile)) {
            return null;
        }

        return [
            'config_key' => $configKey,
            'config_file' => $configFile,
        ];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function findSlotKey(array $values, string $slotKey): ?string
    {
        foreach (array_keys($values) as $key) {
            if (strcasecmp($key, $slotKey) === 0) {
                return $key;
            }
        }

        return null;
    }

    private function queueReadJob(Instance $instance, User $customer, ConfigSchema $configSchema): Job
    {
        [$path, $name] = $this->splitPath($configSchema->getFilePath());

        $payload = [
            'instance_id' => (string) ($instance->getId() ?? ''),
            'customer_id' => (string) $customer->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'path' => $path,
            'name' => $name,
        ];

        $job = new Job('instance.files.read', $payload);
        $this->entityManager->persist($job);

        $this->auditLogger->log($customer, 'instance.configs.read_requested', [
            'instance_id' => $instance->getId(),
            'config_id' => $configSchema->getId(),
            'config_key' => $configSchema->getConfigKey(),
            'job_id' => $job->getId(),
        ]);

        return $job;
    }

    private function queueWriteJob(Instance $instance, User $customer, ConfigSchema $configSchema, string $content, string $auditEvent): Job
    {
        [$path, $name] = $this->splitPath($configSchema->getFilePath());

        $payload = [
            'instance_id' => (string) ($instance->getId() ?? ''),
            'customer_id' => (string) $customer->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'path' => $path,
            'name' => $name,
            'content_base64' => base64_encode($content),
        ];

        $job = new Job('instance.files.write', $payload);
        $this->entityManager->persist($job);

        $this->auditLogger->log($customer, $auditEvent, [
            'instance_id' => $instance->getId(),
            'config_id' => $configSchema->getId(),
            'config_key' => $configSchema->getConfigKey(),
            'job_id' => $job->getId(),
        ]);

        return $job;
    }

    private function findConfigJob(Instance $instance, User $customer, string $jobId, string $type): Job
    {
        $job = $this->jobRepository->find($jobId);
        if ($job === null || $job->getType() !== $type) {
            throw new NotFoundHttpException('Job not found.');
        }

        $payload = $job->getPayload();
        $payloadCustomerId = (string) ($payload['customer_id'] ?? '');
        $payloadInstanceId = (string) ($payload['instance_id'] ?? '');
        if ($payloadCustomerId !== (string) $customer->getId() || $payloadInstanceId !== (string) $instance->getId()) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        return $job;
    }

    private function buildConfigResponseFromJob(Instance $instance, ConfigSchema $configSchema, Job $job): JsonResponse
    {
        $status = $job->getStatus();
        $result = $job->getResult();

        if (in_array($status, [JobStatus::Queued, JobStatus::Claimed, JobStatus::Running], true)) {
            return new JsonResponse([
                'status' => 'pending',
                'job_id' => $job->getId(),
                'config' => $this->normalizeConfigSchema($configSchema),
                'schema' => $this->configSchemaService->normalizeSchema($configSchema),
                'snapshot' => $this->configSnapshotMeta($instance, $configSchema),
                'apply_mode' => $this->resolveApplyMode($configSchema),
            ]);
        }

        if ($status === JobStatus::Succeeded && $result !== null) {
            $error = null;
            $content = $this->decodeFileContent((string) ($result->getOutput()['content_base64'] ?? ''), $error);
            if ($error !== null) {
                return new JsonResponse([
                    'status' => 'error',
                    'job_id' => $job->getId(),
                    'error' => $error,
                    'snapshot' => $this->configSnapshotMeta($instance, $configSchema),
                ]);
            }

            $parseResult = $this->configSchemaService->parse($configSchema, $content);

            return new JsonResponse([
                'status' => 'ready',
                'job_id' => $job->getId(),
                'config' => $this->normalizeConfigSchema($configSchema),
                'schema' => $this->configSchemaService->normalizeSchema($configSchema),
                'values' => $parseResult->getValues(),
                'raw' => $content,
                'warnings' => $parseResult->getWarnings(),
                'snapshot' => $this->configSnapshotMeta($instance, $configSchema),
                'apply_mode' => $this->resolveApplyMode($configSchema),
            ]);
        }

        $message = (string) ($result?->getOutput()['message'] ?? 'Config read failed.');

        return new JsonResponse([
            'status' => 'error',
            'job_id' => $job->getId(),
            'error' => $message,
            'snapshot' => $this->configSnapshotMeta($instance, $configSchema),
        ]);
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


    private function resolveApplyMode(ConfigSchema $schema): string
    {
        $raw = strtolower(trim((string) ($schema->getSchema()['apply_mode'] ?? $schema->getSchema()['applyMode'] ?? 'restart')));

        return in_array($raw, ['reload', 'restart'], true) ? $raw : 'restart';
    }

    /**
     * @param array<string, mixed> $values
     * @return array<int, array{field: string, message: string, code: string}>
     */
    private function validateValuesAgainstSchema(ConfigSchema $configSchema, array $values): array
    {
        $errors = [];
        $schema = $this->configSchemaService->normalizeSchema($configSchema);
        foreach (($schema['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            $id = (string) ($field['id'] ?? $field['key'] ?? '');
            if ($id === '') {
                continue;
            }

            $label = (string) ($field['label'] ?? $id);
            $type = strtolower((string) ($field['type'] ?? 'string'));
            $required = filter_var($field['required'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $exists = array_key_exists($id, $values);
            $value = $exists ? $values[$id] : null;

            if ($required && (!$exists || $value === null || $value === '')) {
                $errors[] = ['field' => $id, 'message' => sprintf('%s is required.', $label), 'code' => 'required'];
                continue;
            }
            if (!$exists || $value === null || $value === '') {
                continue;
            }

            if (in_array($type, ['int', 'integer', 'float', 'number'], true) && !is_numeric($value)) {
                $errors[] = ['field' => $id, 'message' => sprintf('%s must be numeric.', $label), 'code' => 'invalid_number'];
                continue;
            }

            if (in_array($type, ['bool', 'boolean'], true)) {
                $normalized = strtolower((string) $value);
                if (!in_array($normalized, ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], true)) {
                    $errors[] = ['field' => $id, 'message' => sprintf('%s must be boolean.', $label), 'code' => 'invalid_boolean'];
                }
            }

            if (isset($field['options']) && is_array($field['options']) && $field['options'] !== []) {
                $allowed = array_map('strval', array_keys($field['options']));
                if (!in_array((string) $value, $allowed, true)) {
                    $errors[] = ['field' => $id, 'message' => sprintf('%s has an invalid value.', $label), 'code' => 'invalid_choice'];
                }
            }
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>
     */
    private function configSnapshotMeta(Instance $instance, ConfigSchema $schema): array
    {
        $overrides = $instance->getConfigOverrides();
        $entry = $overrides[$schema->getFilePath()] ?? null;
        if (!is_array($entry)) {
            return [];
        }

        return [
            'last_updated_at' => (string) ($entry['last_updated_at'] ?? $entry['updated_at'] ?? ''),
            'last_hash' => (string) ($entry['last_hash'] ?? ''),
            'previous_hash' => (string) ($entry['previous_hash'] ?? ''),
        ];
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
            'apply_mode' => $this->resolveApplyMode($schema),
        ];
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (
            !$actor instanceof User
            || (!$actor->isAdmin() && $actor->getType() !== UserType::Customer)
        ) {
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

        if (!$customer->isAdmin() && $instance->getCustomer()->getId() !== $customer->getId()) {
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

    private function decodeFileContent(string $encoded, ?string &$error): string
    {
        if ($encoded === '') {
            return '';
        }

        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            $error = 'Invalid file content.';
            return '';
        }

        return $decoded;
    }
}
