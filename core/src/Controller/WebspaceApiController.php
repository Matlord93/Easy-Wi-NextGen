<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\Webspace;
use App\Enum\UserType;
use App\Repository\AgentRepository;
use App\Repository\UserRepository;
use App\Repository\WebspaceRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/api')]
final class WebspaceApiController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly AgentRepository $agentRepository,
        private readonly WebspaceRepository $webspaceRepository,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/admin/webspaces', name: 'admin_create_webspace', methods: ['POST'])]
    public function createWebspace(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            return new JsonResponse(['error' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $payload = $request->toArray();
        $customerId = $payload['customer_id'] ?? null;
        $nodeId = (string) ($payload['node_id'] ?? '');
        $path = trim((string) ($payload['path'] ?? ''));
        $phpVersion = trim((string) ($payload['php_version'] ?? ''));
        $quotaValue = $payload['quota'] ?? null;

        if ($customerId === null || $nodeId === '' || $path === '' || $phpVersion === '' || $quotaValue === null) {
            return new JsonResponse(['error' => 'Missing required fields.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (!is_numeric($quotaValue)) {
            return new JsonResponse(['error' => 'Quota must be numeric.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $quota = (int) $quotaValue;
        if ($quota < 0) {
            return new JsonResponse(['error' => 'Quota must be zero or positive.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $customer = $this->userRepository->find($customerId);
        if ($customer === null || $customer->getType() !== UserType::Customer) {
            return new JsonResponse(['error' => 'Customer not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $node = $this->agentRepository->find($nodeId);
        if ($node === null) {
            return new JsonResponse(['error' => 'Node not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $webspace = new Webspace($customer, $node, $path, $phpVersion, $quota);
        $this->entityManager->persist($webspace);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'webspace.created', [
            'webspace_id' => $webspace->getId(),
            'customer_id' => $customer->getId(),
            'node_id' => $node->getId(),
            'path' => $webspace->getPath(),
            'php_version' => $webspace->getPhpVersion(),
            'quota' => $webspace->getQuota(),
        ]);
        $this->entityManager->flush();

        return new JsonResponse([
            'id' => $webspace->getId(),
            'customer_id' => $customer->getId(),
            'node_id' => $node->getId(),
            'path' => $webspace->getPath(),
            'php_version' => $webspace->getPhpVersion(),
            'quota' => $webspace->getQuota(),
        ], JsonResponse::HTTP_CREATED);
    }

    #[Route(path: '/webspaces', name: 'customer_webspaces', methods: ['GET'])]
    public function listWebspaces(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            return new JsonResponse(['error' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $webspaces = $this->webspaceRepository->findByCustomer($actor);
        $payload = [];

        foreach ($webspaces as $webspace) {
            $node = $webspace->getNode();
            $payload[] = [
                'id' => $webspace->getId(),
                'node' => [
                    'id' => $node->getId(),
                    'name' => $node->getName(),
                ],
                'path' => $webspace->getPath(),
                'php_version' => $webspace->getPhpVersion(),
                'quota' => $webspace->getQuota(),
            ];
        }

        return new JsonResponse(['webspaces' => $payload]);
    }
}
