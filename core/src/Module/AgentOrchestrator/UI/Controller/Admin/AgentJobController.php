<?php

declare(strict_types=1);

namespace App\Module\AgentOrchestrator\UI\Controller\Admin;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcher;
use App\Repository\AgentJobRepository;
use App\Repository\AgentRepository;
use App\Module\Core\Domain\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/admin')]
final class AgentJobController
{
    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly AgentJobRepository $jobRepository,
        private readonly AgentJobDispatcher $jobDispatcher,
    ) {
    }

    #[Route(path: '/nodes/{id}/jobs', name: 'admin_nodes_agent_jobs_create', methods: ['POST'])]
    public function createJob(Request $request, string $id): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new JsonResponse(['error' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $node = $this->agentRepository->find($id);
        if ($node === null) {
            return new JsonResponse(['error' => 'Node not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $payload = $request->toArray();
        $type = trim((string) ($payload['type'] ?? ''));
        $jobPayload = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];

        if ($type === '') {
            return new JsonResponse(['error' => 'Job type is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $job = $this->jobDispatcher->dispatch($node, $type, $jobPayload);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($this->normalizeJob($job), JsonResponse::HTTP_CREATED);
    }

    #[Route(path: '/agent-jobs/{id}', name: 'admin_agent_jobs_show', methods: ['GET'])]
    public function showJob(Request $request, string $id): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new JsonResponse(['error' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $job = $this->jobRepository->find($id);
        if ($job === null) {
            return new JsonResponse(['error' => 'Job not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->normalizeJob($job));
    }

    private function normalizeJob(\App\Module\AgentOrchestrator\Domain\Entity\AgentJob $job): array
    {
        return [
            'id' => $job->getId(),
            'node_id' => $job->getNode()->getId(),
            'type' => $job->getType(),
            'payload' => $job->getPayload(),
            'status' => $job->getStatus()->value,
            'created_at' => $job->getCreatedAt()->format(DATE_RFC3339),
            'started_at' => $job->getStartedAt()?->format(DATE_RFC3339),
            'finished_at' => $job->getFinishedAt()?->format(DATE_RFC3339),
            'log_text' => $job->getLogText(),
            'error_text' => $job->getErrorText(),
            'retries' => $job->getRetries(),
            'idempotency_key' => $job->getIdempotencyKey(),
            'result_payload' => $job->getResultPayload(),
        ];
    }
}
