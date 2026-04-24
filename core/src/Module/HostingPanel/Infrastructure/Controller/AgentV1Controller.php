<?php

declare(strict_types=1);

namespace App\Module\HostingPanel\Infrastructure\Controller;

use App\Module\HostingPanel\Application\Job\DispatchJobMessage;
use App\Module\HostingPanel\Application\Security\AgentTokenAuthenticator;
use App\Module\HostingPanel\Domain\Entity\Agent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/hp/agent', name: 'hp_agent_v1_')]
class AgentV1Controller extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route('/heartbeat', name: 'heartbeat', methods: ['POST'])]
    public function heartbeat(Request $request, AgentTokenAuthenticator $authenticator): JsonResponse
    {
        $payload = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $agent = $this->authenticateRequest($request, $payload, $authenticator);
        if (!$agent instanceof Agent) {
            return $this->json(['error' => 'unauthorized'], 401);
        }

        $agent->updateHeartbeat((string) ($payload['version'] ?? 'unknown'), (string) ($payload['os'] ?? 'unknown'), (array) ($payload['capabilities'] ?? []));
        $this->entityManager->persist($agent);
        $this->entityManager->flush();

        return $this->json(['ok' => true, 'retry_after_ms' => 5000]);
    }

    #[Route('/commands/dispatch', name: 'command_dispatch', methods: ['POST'])]
    public function dispatch(Request $request, MessageBusInterface $bus, AgentTokenAuthenticator $authenticator): JsonResponse
    {
        $payload = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $agent = $this->authenticateRequest($request, $payload, $authenticator);
        if (!$agent instanceof Agent) {
            return $this->json(['error' => 'unauthorized'], 401);
        }

        $idempotencyKey = (string) $request->headers->get('Idempotency-Key', '');
        if ($idempotencyKey === '') {
            return $this->json(['error' => 'missing idempotency key'], 400);
        }

        $bus->dispatch(new DispatchJobMessage(
            (int) ($payload['node_id'] ?? 0),
            (string) ($payload['type'] ?? 'unknown'),
            $idempotencyKey,
            (array) ($payload['payload'] ?? []),
        ));

        return $this->json(['status' => 'queued', 'idempotency_key' => $idempotencyKey], 202);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function authenticateRequest(Request $request, array $payload, AgentTokenAuthenticator $authenticator): ?Agent
    {
        $agentUuid = trim((string) ($payload['agent_uuid'] ?? $request->headers->get('X-Agent-UUID', '')));
        if ($agentUuid === '') {
            return null;
        }

        $token = preg_replace('/^Bearer\s+/i', '', trim((string) $request->headers->get('Authorization', ''))) ?? '';
        return $authenticator->authenticate($agentUuid, $token);
    }
}
