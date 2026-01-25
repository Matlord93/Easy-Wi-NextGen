<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\ConfigSchema;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\JobStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\ConfigSchemaRepository;
use App\Repository\GameDefinitionRepository;
use App\Repository\InstanceRepository;
use App\Repository\JobRepository;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\ConfigSchema\ConfigSchemaService;
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
        private readonly ConfigSchemaService $configSchemaService,
        private readonly JobRepository $jobRepository,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
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

            return $this->buildConfigResponseFromJob($configSchema, $job);
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

        $content = $this->configSchemaService->generate($configSchema, $values);
        $job = $this->queueWriteJob($instance, $customer, $configSchema, $content, 'instance.configs.generated_requested');
        $this->entityManager->flush();

        return new JsonResponse([
            'status' => 'queued',
            'job_id' => $job->getId(),
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
        $job = $this->queueWriteJob($instance, $customer, $configSchema, $content, 'instance.configs.updated_requested');
        $this->entityManager->flush();

        return new JsonResponse([
            'status' => 'queued',
            'job_id' => $job->getId(),
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

    private function buildConfigResponseFromJob(ConfigSchema $configSchema, Job $job): JsonResponse
    {
        $status = $job->getStatus();
        $result = $job->getResult();

        if (in_array($status, [JobStatus::Queued, JobStatus::Running], true)) {
            return new JsonResponse([
                'status' => 'pending',
                'job_id' => $job->getId(),
                'config' => $this->normalizeConfigSchema($configSchema),
                'schema' => $this->configSchemaService->normalizeSchema($configSchema),
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
            ]);
        }

        $message = (string) ($result?->getOutput()['message'] ?? 'Config read failed.');

        return new JsonResponse([
            'status' => 'error',
            'job_id' => $job->getId(),
            'error' => $message,
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
