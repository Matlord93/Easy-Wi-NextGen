<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PortBlock;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\PortBlockRepository;
use App\Repository\PortPoolRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\PortLeaseManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class PortBlockApiController
{
    public function __construct(
        private readonly PortBlockRepository $portBlockRepository,
        private readonly PortPoolRepository $portPoolRepository,
        private readonly UserRepository $userRepository,
        private readonly PortLeaseManager $portLeaseManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route(path: '/api/port-blocks', name: 'port_blocks_list', methods: ['GET'])]
    #[Route(path: '/api/v1/admin/port-blocks', name: 'admin_port_blocks_list_v1', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/port-blocks', name: 'customer_port_blocks_list_v1', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $actor = $this->requireUser($request);

        $blocks = $actor->getType() === UserType::Admin
            ? $this->portBlockRepository->findBy([], ['createdAt' => 'DESC'])
            : $this->portBlockRepository->findByCustomer($actor);

        return new JsonResponse([
            'port_blocks' => array_map(fn (PortBlock $block) => $this->normalizeBlock($block), $blocks),
        ]);
    }

    #[Route(path: '/api/admin/port-blocks', name: 'admin_port_blocks_create', methods: ['POST'])]
    #[Route(path: '/api/v1/admin/port-blocks', name: 'admin_port_blocks_create_v1', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $actor = $this->requireAdmin($request);

        $payload = $request->request->all();
        if ($payload === []) {
            try {
                $payload = $request->toArray();
            } catch (\JsonException $exception) {
                return new JsonResponse(['error' => 'Invalid JSON payload.'], JsonResponse::HTTP_BAD_REQUEST);
            }
        }

        $poolId = $payload['pool_id'] ?? null;
        $customerId = $payload['customer_id'] ?? null;
        $sizeValue = $payload['size'] ?? null;
        $startPortValue = $payload['start_port'] ?? null;
        $endPortValue = $payload['end_port'] ?? null;

        if (!is_numeric($poolId) || !is_numeric($customerId) || !is_numeric($sizeValue)) {
            return new JsonResponse(['error' => 'pool_id, customer_id, and size are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $size = (int) $sizeValue;
        if ($size <= 0 || $size > 65535) {
            return new JsonResponse(['error' => 'Size must be between 1 and 65535.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $pool = $this->portPoolRepository->find((int) $poolId);
        if ($pool === null) {
            return new JsonResponse(['error' => 'Port pool not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $customer = $this->userRepository->find((int) $customerId);
        if ($customer === null || $customer->getType() !== UserType::Customer) {
            return new JsonResponse(['error' => 'Customer not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        try {
            if ($startPortValue !== null && $endPortValue !== null) {
                if (!is_numeric($startPortValue) || !is_numeric($endPortValue)) {
                    return new JsonResponse(['error' => 'start_port and end_port must be numeric.'], JsonResponse::HTTP_BAD_REQUEST);
                }

                $blocks = $this->portLeaseManager->allocateBlocksInRange(
                    $pool,
                    $customer,
                    (int) $startPortValue,
                    (int) $endPortValue,
                    $size,
                );
            } else {
                $blocks = [$this->portLeaseManager->allocateBlock($pool, $customer, $size)];
            }
        } catch (\RuntimeException | \InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }

        foreach ($blocks as $block) {
            $this->entityManager->persist($block);
            $this->auditLogger->log($actor, 'port_block.created', [
                'port_block_id' => $block->getId(),
                'port_pool_id' => $pool->getId(),
                'customer_id' => $customer->getId(),
                'start_port' => $block->getStartPort(),
                'end_port' => $block->getEndPort(),
            ]);
        }

        $this->entityManager->flush();

        $normalizedBlocks = array_map(fn (PortBlock $block) => $this->normalizeBlock($block), $blocks);

        $response = ['port_blocks' => $normalizedBlocks];
        if (count($normalizedBlocks) === 1) {
            $response['port_block'] = $normalizedBlocks[0];
        }

        return new JsonResponse($response, JsonResponse::HTTP_CREATED);
    }

    private function normalizeBlock(PortBlock $block): array
    {
        $instance = $block->getInstance();

        return [
            'id' => $block->getId(),
            'pool_id' => $block->getPool()->getId(),
            'customer_id' => $block->getCustomer()->getId(),
            'instance_id' => $instance?->getId(),
            'start_port' => $block->getStartPort(),
            'end_port' => $block->getEndPort(),
            'assigned_at' => $block->getAssignedAt()?->format(DATE_RFC3339),
            'released_at' => $block->getReleasedAt()?->format(DATE_RFC3339),
        ];
    }

    private function requireUser(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    private function requireAdmin(Request $request): User
    {
        $actor = $this->requireUser($request);
        if ($actor->getType() !== UserType::Admin) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }
}
