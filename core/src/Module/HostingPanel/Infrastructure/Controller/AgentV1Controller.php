<?php

declare(strict_types=1);

namespace App\Module\HostingPanel\Infrastructure\Controller;

use App\Module\HostingPanel\Application\Job\DispatchJobMessage;
use App\Module\HostingPanel\Application\Security\AgentTokenAuthenticator;
use App\Module\HostingPanel\Domain\Entity\Agent;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/agent', name: 'hp_agent_v1_')]
class AgentV1Controller extends AbstractController
{
    #[Route('/heartbeat', name: 'heartbeat', methods: ['POST'])]
    public function heartbeat(Request $request, AgentTokenAuthenticator $authenticator): JsonResponse
    {
        $payload = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $agentUuid = (string) ($payload['agent_uuid'] ?? '');
        $token = str_replace('Bearer ', '', (string) $request->headers->get('Authorization', ''));

        $agent = $authenticator->authenticate($agentUuid, $token);
        if (!$agent instanceof Agent) {
            return $this->json(['error' => 'unauthorized'], 401);
        }

        $agent->updateHeartbeat((string) ($payload['version'] ?? 'unknown'), (string) ($payload['os'] ?? 'unknown'), (array) ($payload['capabilities'] ?? []));

        return $this->json(['ok' => true, 'retry_after_ms' => 5000]);
    }

    #[Route('/commands/dispatch', name: 'command_dispatch', methods: ['POST'])]
    public function dispatch(Request $request, MessageBusInterface $bus): JsonResponse
    {
        $payload = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
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
}
