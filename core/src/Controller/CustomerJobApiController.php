<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Instance;
use App\Entity\Job;
use App\Entity\User;
use App\Enum\UserType;
use App\Message\RunInstanceActionMessage;
use App\Repository\InstanceRepository;
use App\Repository\JobLogRepository;
use App\Repository\JobRepository;
use App\Service\DiskEnforcementService;
use App\Service\JobLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/api/customer')]
final class CustomerJobApiController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly JobRepository $jobRepository,
        private readonly JobLogRepository $jobLogRepository,
        private readonly JobLogger $jobLogger,
        private readonly DiskEnforcementService $diskEnforcementService,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(path: '/instances/{id}/actions', name: 'customer_instance_actions_api', methods: ['POST'])]
    public function createAction(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $payload = $this->parsePayload($request);

        $jobType = $this->resolveJobType($payload);
        if ($jobType === null) {
            throw new BadRequestHttpException('Invalid action type.');
        }

        if (in_array($jobType, ['instance.reinstall', 'instance.backup.create', 'instance.backup.restore', 'instance.addon.install', 'instance.addon.remove', 'instance.addon.update'], true)) {
            $blockMessage = $this->diskEnforcementService->guardInstanceAction($instance, new \DateTimeImmutable());
            if ($blockMessage !== null) {
                return new JsonResponse(['error' => $blockMessage], JsonResponse::HTTP_BAD_REQUEST);
            }
        }

        $extraPayload = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        $jobPayload = array_merge($extraPayload, $this->buildBasePayload($instance));

        $job = new Job($jobType, $jobPayload);
        $this->entityManager->persist($job);
        $this->jobLogger->log($job, 'Job queued.', 0);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new RunInstanceActionMessage($job->getId()));

        return new JsonResponse([
            'job' => $this->normalizeJob($job),
        ], JsonResponse::HTTP_ACCEPTED);
    }

    #[Route(path: '/jobs/{jobId}', name: 'customer_job_api_show', methods: ['GET'])]
    public function showJob(Request $request, string $jobId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $job = $this->findCustomerJob($customer, $jobId);

        return new JsonResponse($this->normalizeJob($job));
    }

    #[Route(path: '/jobs/{jobId}/logs', name: 'customer_job_api_logs', methods: ['GET'])]
    public function listLogs(Request $request, string $jobId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $job = $this->findCustomerJob($customer, $jobId);
        $afterIdParam = $request->query->get('afterId');
        $afterId = is_numeric($afterIdParam) ? (int) $afterIdParam : null;

        $logs = $this->jobLogRepository->findByJobAfterId($job, $afterId);

        return new JsonResponse([
            'logs' => array_map(fn ($log) => [
                'id' => $log->getId(),
                'message' => $log->getMessage(),
                'progress' => $log->getProgress(),
                'created_at' => $log->getCreatedAt()->format(DATE_ATOM),
            ], $logs),
        ]);
    }

    #[Route(path: '/jobs/{jobId}/cancel', name: 'customer_job_api_cancel', methods: ['POST'])]
    public function cancel(Request $request, string $jobId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $job = $this->findCustomerJob($customer, $jobId);

        if ($job->getStatus()->isTerminal()) {
            return new JsonResponse(['error' => 'Job already completed.'], JsonResponse::HTTP_CONFLICT);
        }

        if ($job->getStatus()->value !== 'queued') {
            return new JsonResponse(['error' => 'Only queued jobs can be cancelled.'], JsonResponse::HTTP_CONFLICT);
        }

        $job->transitionTo(\App\Enum\JobStatus::Cancelled);
        $this->jobLogger->log($job, 'Job cancelled.', 100);
        $this->entityManager->flush();

        return new JsonResponse($this->normalizeJob($job));
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

    private function findCustomerJob(User $customer, string $jobId): Job
    {
        $job = $this->jobRepository->find($jobId);
        if ($job === null) {
            throw new NotFoundHttpException('Job not found.');
        }

        $payload = $job->getPayload();
        $payloadCustomerId = $payload['customer_id'] ?? null;
        if (is_string($payloadCustomerId) || is_int($payloadCustomerId)) {
            if ((string) $payloadCustomerId === (string) $customer->getId()) {
                return $job;
            }
        }

        $payloadInstanceId = $payload['instance_id'] ?? null;
        if (is_string($payloadInstanceId) || is_int($payloadInstanceId)) {
            $instance = $this->instanceRepository->find((int) $payloadInstanceId);
            if ($instance !== null && $instance->getCustomer()->getId() === $customer->getId()) {
                return $job;
            }
        }

        throw new AccessDeniedHttpException('Forbidden.');
    }

    /**
     * @return array<string, mixed>
     */
    private function parsePayload(Request $request): array
    {
        try {
            $payload = $request->toArray();
        } catch (\JsonException $exception) {
            throw new BadRequestHttpException('Invalid JSON payload.', $exception);
        }

        return is_array($payload) ? $payload : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeJob(Job $job): array
    {
        $result = $job->getResult();

        return [
            'id' => $job->getId(),
            'type' => $job->getType(),
            'status' => $job->getStatus()->value,
            'progress' => $job->getProgress(),
            'created_at' => $job->getCreatedAt()->format(DATE_ATOM),
            'updated_at' => $job->getUpdatedAt()->format(DATE_ATOM),
            'result' => $result ? [
                'status' => $result->getStatus()->value,
                'output' => $result->getOutput(),
                'completed_at' => $result->getCompletedAt()->format(DATE_ATOM),
            ] : null,
        ];
    }

    private function resolveJobType(array $payload): ?string
    {
        $action = trim((string) ($payload['action'] ?? ''));
        $type = trim((string) ($payload['type'] ?? ''));

        if ($type !== '') {
            return $this->isAllowedJobType($type) ? $type : null;
        }

        return match ($action) {
            'start' => 'instance.start',
            'stop' => 'instance.stop',
            'restart' => 'instance.restart',
            'reinstall' => 'instance.reinstall',
            default => null,
        };
    }

    private function isAllowedJobType(string $type): bool
    {
        $allowed = [
            'instance.start',
            'instance.stop',
            'instance.restart',
            'instance.reinstall',
            'instance.backup.create',
            'instance.backup.restore',
            'instance.addon.install',
            'instance.addon.remove',
            'instance.addon.update',
        ];

        return in_array($type, $allowed, true);
    }

    /**
     * @return array<string, string>
     */
    private function buildBasePayload(Instance $instance): array
    {
        return [
            'instance_id' => (string) ($instance->getId() ?? ''),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'node_id' => $instance->getNode()->getId(),
            'agent_id' => $instance->getNode()->getId(),
        ];
    }
}
