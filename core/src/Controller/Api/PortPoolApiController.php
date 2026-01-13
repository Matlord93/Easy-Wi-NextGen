<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\PortPool;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\AgentRepository;
use App\Repository\PortPoolRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class PortPoolApiController
{
    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly PortPoolRepository $portPoolRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route(path: '/api/admin/port-pools', name: 'admin_port_pools_list', methods: ['GET'])]
    #[Route(path: '/api/v1/admin/port-pools', name: 'admin_port_pools_list_v1', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $actor = $this->requireAdmin($request);

        $pools = $this->portPoolRepository->findBy([], ['createdAt' => 'DESC']);
        $payload = [];

        foreach ($pools as $pool) {
            $node = $pool->getNode();
            $payload[] = [
                'id' => $pool->getId(),
                'name' => $pool->getName(),
                'node' => [
                    'id' => $node->getId(),
                    'name' => $node->getName(),
                ],
                'start_port' => $pool->getStartPort(),
                'end_port' => $pool->getEndPort(),
                'created_at' => $pool->getCreatedAt()->format(DATE_RFC3339),
            ];
        }

        return new JsonResponse(['port_pools' => $payload]);
    }

    #[Route(path: '/api/admin/port-pools', name: 'admin_port_pools_create', methods: ['POST'])]
    #[Route(path: '/api/v1/admin/port-pools', name: 'admin_port_pools_create_v1', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $actor = $this->requireAdmin($request);

        try {
            $payload = $request->toArray();
        } catch (\JsonException $exception) {
            return new JsonResponse(['error' => 'Invalid JSON payload.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $nodeId = (string) ($payload['node_id'] ?? '');
        $name = trim((string) ($payload['name'] ?? ''));
        $startValue = $payload['start_port'] ?? null;
        $endValue = $payload['end_port'] ?? null;

        if ($nodeId === '' || $name === '' || !is_numeric($startValue) || !is_numeric($endValue)) {
            return new JsonResponse(['error' => 'Node, name, start_port, and end_port are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $startPort = (int) $startValue;
        $endPort = (int) $endValue;
        if ($startPort <= 0 || $endPort <= 0 || $startPort > 65535 || $endPort > 65535 || $startPort > $endPort) {
            return new JsonResponse(['error' => 'Port range must be between 1 and 65535.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $node = $this->agentRepository->find($nodeId);
        if ($node === null) {
            return new JsonResponse(['error' => 'Node not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $pool = new PortPool($node, $name, $startPort, $endPort);
        $this->entityManager->persist($pool);

        $this->auditLogger->log($actor, 'port_pool.created', [
            'port_pool_id' => $pool->getId(),
            'node_id' => $node->getId(),
            'name' => $pool->getName(),
            'start_port' => $pool->getStartPort(),
            'end_port' => $pool->getEndPort(),
        ]);

        $this->entityManager->flush();

        return new JsonResponse([
            'id' => $pool->getId(),
            'name' => $pool->getName(),
            'node_id' => $node->getId(),
            'start_port' => $pool->getStartPort(),
            'end_port' => $pool->getEndPort(),
        ], JsonResponse::HTTP_CREATED);
    }

    private function requireAdmin(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }
}
