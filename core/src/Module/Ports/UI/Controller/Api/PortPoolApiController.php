<?php

declare(strict_types=1);

namespace App\Module\Ports\UI\Controller\Api;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\User;
use App\Module\Ports\Domain\Entity\PortPool;
use App\Module\Ports\Infrastructure\Repository\PortPoolRepository;
use App\Repository\AgentRepository;
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
                'tag' => $pool->getTag(),
                'game_scope' => $pool->getGameScope(),
                'node' => [
                    'id' => $node->getId(),
                    'name' => $node->getName(),
                ],
                'start_port' => $pool->getStartPort(),
                'end_port' => $pool->getEndPort(),
                'enabled' => $pool->isEnabled(),
                'allocation_step' => $pool->getAllocationStep(),
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
        $tag = trim((string) ($payload['tag'] ?? ''));
        $startValue = $payload['start_port'] ?? null;
        $endValue = $payload['end_port'] ?? null;
        $enabled = (bool) ($payload['enabled'] ?? true);
        $allocationStep = is_numeric($payload['allocation_step'] ?? null) ? (int) $payload['allocation_step'] : 1;
        $allocationStep = in_array($allocationStep, [1, 10], true) ? $allocationStep : 1;

        if ('' === $nodeId || '' === $name || '' === $tag || !is_numeric($startValue) || !is_numeric($endValue)) {
            return new JsonResponse(['error' => 'Node, name, tag, start_port, and end_port are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $startPort = (int) $startValue;
        $endPort = (int) $endValue;
        if ($startPort <= 0 || $endPort <= 0 || $startPort > 65535 || $endPort > 65535 || $startPort > $endPort) {
            return new JsonResponse(['error' => 'Port range must be between 1 and 65535.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $node = $this->agentRepository->find($nodeId);
        if (null === $node) {
            return new JsonResponse(['error' => 'Node not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $pool = new PortPool($node, $name, $tag, $startPort, $endPort, $enabled, $allocationStep);
        try {
            $pool->setGameScope((string) ($payload['game_scope'] ?? 'all'));
        } catch (\InvalidArgumentException $error) {
            return new JsonResponse(['error' => $error->getMessage()], 400);
        }
        $this->entityManager->persist($pool);

        $this->auditLogger->log($actor, 'port_pool.created', [
            'port_pool_id' => $pool->getId(),
            'node_id' => $node->getId(),
            'name' => $pool->getName(),
            'tag' => $pool->getTag(),
            'game_scope' => $pool->getGameScope(),
            'start_port' => $pool->getStartPort(),
            'end_port' => $pool->getEndPort(),
            'enabled' => $pool->isEnabled(),
        ]);

        $this->entityManager->flush();

        return new JsonResponse([
            'id' => $pool->getId(),
            'name' => $pool->getName(),
            'tag' => $pool->getTag(),
            'game_scope' => $pool->getGameScope(),
            'node_id' => $node->getId(),
            'start_port' => $pool->getStartPort(),
            'end_port' => $pool->getEndPort(),
            'enabled' => $pool->isEnabled(),
            'allocation_step' => $pool->getAllocationStep(),
        ], JsonResponse::HTTP_CREATED);
    }

    #[Route(path: '/api/admin/port-pools/{id}', name: 'admin_port_pool_update_scope', methods: ['PATCH'], requirements: ['id' => '\\d+'])]
    #[Route(path: '/api/v1/admin/port-pools/{id}', name: 'admin_port_pool_update_scope_v1', methods: ['PATCH'], requirements: ['id' => '\\d+'])]
    public function updateScope(Request $request, int $id): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        try {
            $input = $request->toArray();
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Invalid JSON'], 400);
        }
        $pool = $this->portPoolRepository->find($id);
        if (!$pool instanceof PortPool) {
            return new JsonResponse(['error' => 'Port pool not found'], 404);
        }
        try {
            $this->entityManager->wrapInTransaction(function () use ($pool, $input, $actor): void {
                $this->entityManager->lock($pool->getNode(), \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                $pool->setGameScope((string) ($input['game_scope'] ?? ''));
                $this->auditLogger->log($actor, 'port_pool.scope_updated', ['port_pool_id' => $pool->getId(), 'game_scope' => $pool->getGameScope()]);
            });
        } catch (\InvalidArgumentException $error) {
            return new JsonResponse(['error' => $error->getMessage()], 400);
        }

        return new JsonResponse(['id' => $pool->getId(), 'game_scope' => $pool->getGameScope()]);
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
