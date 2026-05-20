<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Api;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Gameserver\Infrastructure\Client\AgentGameServerClient;
use App\Repository\InstanceRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class InstanceTemplateProvisionApiController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly AgentGameServerClient $agentGameServerClient,
    ) {
    }

    #[Route(path: '/api/admin/instances/{id}/provision-template', name: 'admin_instances_provision_template_api', methods: ['POST'])]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new JsonResponse(['error' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $instance = $this->instanceRepository->find((int) $id);
        if (!$instance instanceof Instance) {
            return new JsonResponse(['error' => 'Instance not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $installResolver = $instance->getTemplate()->getInstallResolver();
        $masterDir = trim((string) ($installResolver['master_dir'] ?? ''));
        if ($masterDir === '') {
            return new JsonResponse(['error' => 'Template master_dir missing in install_resolver.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $targetDir = trim((string) $instance->getInstallPath());
        if ($targetDir === '') {
            return new JsonResponse(['error' => 'Instance install path is empty.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $payload = [
            'game_type' => $instance->getTemplate()->getGameKey(),
            'server_id' => (string) $instance->getId(),
            'master_dir' => $masterDir,
            'target_dir' => $targetDir,
        ];

        try {
            $response = $this->agentGameServerClient->createFromMasterTemplate($instance, $payload);

            return new JsonResponse([
                'ok' => true,
                'agent_response' => $response,
            ]);
        } catch (\Throwable $exception) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'Provisioning via Go-Agent failed.',
                'details' => $exception->getMessage(),
            ], JsonResponse::HTTP_BAD_GATEWAY);
        }
    }
}
