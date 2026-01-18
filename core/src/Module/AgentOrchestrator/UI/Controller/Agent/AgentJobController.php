<?php

declare(strict_types=1);

namespace App\Module\AgentOrchestrator\UI\Controller\Agent;

use App\Module\AgentOrchestrator\Application\AgentJobResultApplier;
use App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus;
use App\Module\Core\Application\AgentSignatureVerifier;
use App\Module\Core\Application\EncryptionService;
use App\Repository\AgentJobRepository;
use App\Repository\AgentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;

#[Route(path: '/agent')]
final class AgentJobController
{
    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly AgentJobRepository $jobRepository,
        private readonly AgentSignatureVerifier $signatureVerifier,
        private readonly EncryptionService $encryptionService,
        private readonly AgentJobResultApplier $resultApplier,
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheInterface $cache,
    ) {
    }

    #[Route(path: '/{nodeId}/jobs', name: 'agent_jobs_poll', methods: ['GET'])]
    public function pollJobs(Request $request, string $nodeId): JsonResponse
    {
        $agent = $this->requireAgent($request, $nodeId);

        $limit = (int) $request->query->get('limit', 1);
        $jobs = $this->jobRepository->findQueuedForNode($agent->getId(), max(1, $limit));

        return new JsonResponse([
            'jobs' => array_map([$this, 'normalizeJob'], $jobs),
        ]);
    }

    #[Route(path: '/{nodeId}/jobs/{jobId}/start', name: 'agent_jobs_start', methods: ['POST'])]
    public function startJob(Request $request, string $nodeId, string $jobId): JsonResponse
    {
        $this->requireAgent($request, $nodeId);
        $job = $this->jobRepository->find($jobId);
        if ($job === null || $job->getNode()->getId() !== $nodeId) {
            return new JsonResponse(['error' => 'Job not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        if ($job->getStatus() !== AgentJobStatus::Queued) {
            return new JsonResponse(['error' => 'Job not queued.'], JsonResponse::HTTP_CONFLICT);
        }

        $job->markRunning();
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'ok']);
    }

    #[Route(path: '/{nodeId}/jobs/{jobId}/finish', name: 'agent_jobs_finish', methods: ['POST'])]
    public function finishJob(Request $request, string $nodeId, string $jobId): JsonResponse
    {
        $this->requireAgent($request, $nodeId);
        $job = $this->jobRepository->find($jobId);
        if ($job === null || $job->getNode()->getId() !== $nodeId) {
            return new JsonResponse(['error' => 'Job not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $payload = $request->toArray();
        $statusRaw = strtolower((string) ($payload['status'] ?? ''));
        $status = match ($statusRaw) {
            'success' => AgentJobStatus::Success,
            'failed' => AgentJobStatus::Failed,
            default => throw new BadRequestHttpException('Invalid status.'),
        };

        $job->setLogText(is_string($payload['log_text'] ?? null) ? $payload['log_text'] : null);
        $job->setErrorText(is_string($payload['error_text'] ?? null) ? $payload['error_text'] : null);
        $job->setResultPayload(is_array($payload['result_payload'] ?? null) ? $payload['result_payload'] : null);
        $job->markFinished($status);
        $this->applyViewerSnapshotCache($job, $payload['result_payload'] ?? null);
        $this->resultApplier->apply($job, $status, $job->getResultPayload());
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'ok']);
    }

    private function requireAgent(Request $request, string $nodeId): \App\Module\Core\Domain\Entity\Agent
    {
        $agentId = (string) $request->headers->get('X-Agent-ID', '');
        if ($agentId === '' || $agentId !== $nodeId) {
            throw new UnauthorizedHttpException('hmac', 'Missing or mismatched agent id.');
        }

        $agent = $this->agentRepository->find($agentId);
        if ($agent === null) {
            throw new UnauthorizedHttpException('hmac', 'Unknown agent.');
        }

        $secret = $this->encryptionService->decrypt($agent->getSecretPayload());
        $this->signatureVerifier->verify($request, $agentId, $secret);

        return $agent;
    }

    private function normalizeJob(\App\Module\AgentOrchestrator\Domain\Entity\AgentJob $job): array
    {
        return [
            'id' => $job->getId(),
            'type' => $job->getType(),
            'payload' => $job->getPayload(),
            'created_at' => $job->getCreatedAt()->format(DATE_RFC3339),
        ];
    }

    private function applyViewerSnapshotCache(\App\Module\AgentOrchestrator\Domain\Entity\AgentJob $job, mixed $resultPayload): void
    {
        if (!is_array($resultPayload)) {
            return;
        }

        if (!in_array($job->getType(), ['ts3.viewer.snapshot', 'ts6.viewer.snapshot'], true)) {
            return;
        }

        $cacheKey = $job->getPayload()['cache_key'] ?? null;
        if (!is_string($cacheKey) || $cacheKey === '') {
            return;
        }

        $snapshot = [
            'status' => 'ok',
            'server' => $resultPayload['server'] ?? null,
            'channels' => $resultPayload['channels'] ?? [],
            'clients' => $resultPayload['clients'] ?? [],
            'generated_at' => $resultPayload['generated_at'] ?? (new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        $this->cache->delete($cacheKey);
        $this->cache->get($cacheKey, function () use ($snapshot): array {
            return $snapshot;
        });
    }
}
