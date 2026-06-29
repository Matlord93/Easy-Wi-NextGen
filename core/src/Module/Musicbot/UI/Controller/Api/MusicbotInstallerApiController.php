<?php

declare(strict_types=1);

namespace App\Module\Musicbot\UI\Controller\Api;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Musicbot\Application\MusicbotInstallerService;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Repository\MusicbotInstanceRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * REST API endpoints for musicbot installer, updater, validator and rebuilder.
 *
 * All endpoints are admin-only. They dispatch agent jobs and return job references
 * that the caller can poll for completion via the standard job status endpoint.
 */
final class MusicbotInstallerApiController
{
    public function __construct(
        private readonly MusicbotInstallerService $installerService,
        private readonly MusicbotInstanceRepository $instanceRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * POST /api/v1/admin/musicbots/{id}/install
     *
     * Dispatches a fresh install job for an existing instance record.
     * Use this to (re-)provision the agent-side runtime after the database record
     * was created or after a manual cleanup of the install directory.
     */
    #[Route(
        path: '/api/v1/admin/musicbots/{id}/install',
        name: 'api_v1_admin_musicbots_install',
        requirements: ['id' => '\\d+'],
        methods: ['POST'],
    )]
    public function install(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireAdmin($request);
        $instance = $this->findInstance($id);
        $options = $this->parseOptions($request);

        $result = $this->installerService->install($instance, $admin, $options);

        return new JsonResponse(['data' => $result], 202);
    }

    /**
     * POST /api/v1/admin/musicbots/{id}/update
     *
     * Updates the runtime binary for an existing instance. The agent automatically
     * creates a timestamped backup of the previous binary and triggers a health
     * check after the update completes.
     */
    #[Route(
        path: '/api/v1/admin/musicbots/{id}/update',
        name: 'api_v1_admin_musicbots_update_runtime',
        requirements: ['id' => '\\d+'],
        methods: ['POST'],
    )]
    public function update(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireAdmin($request);
        $instance = $this->findInstance($id);
        $options = $this->parseOptions($request);

        $result = $this->installerService->update($instance, $admin, $options);

        return new JsonResponse(['data' => $result], 202);
    }

    /**
     * POST /api/v1/admin/musicbots/{id}/reinstall
     *
     * Overwrites the existing installation with a clean install. Config is
     * regenerated from the database record; the data directory is preserved.
     */
    #[Route(
        path: '/api/v1/admin/musicbots/{id}/reinstall',
        name: 'api_v1_admin_musicbots_reinstall',
        requirements: ['id' => '\\d+'],
        methods: ['POST'],
    )]
    public function reinstall(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireAdmin($request);
        $instance = $this->findInstance($id);
        $options = $this->parseOptions($request);

        $result = $this->installerService->reinstall($instance, $admin, $options);

        return new JsonResponse(['data' => $result], 202);
    }

    /**
     * POST /api/v1/admin/musicbots/{id}/rebuild
     *
     * Triggers a full repair + health-check cycle. Use this when the instance is
     * in an inconsistent state and a targeted repair action is not sufficient.
     */
    #[Route(
        path: '/api/v1/admin/musicbots/{id}/rebuild',
        name: 'api_v1_admin_musicbots_rebuild',
        requirements: ['id' => '\\d+'],
        methods: ['POST'],
    )]
    public function rebuild(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireAdmin($request);
        $instance = $this->findInstance($id);
        $options = $this->parseOptions($request);

        $result = $this->installerService->rebuild($instance, $admin, $options);

        return new JsonResponse(['data' => $result], 202);
    }

    /**
     * POST /api/v1/admin/musicbots/{id}/validate
     *
     * Triggers a full health check on the agent side. Stores the result in
     * runtimePayload so the health status endpoints reflect the latest state.
     */
    #[Route(
        path: '/api/v1/admin/musicbots/{id}/validate',
        name: 'api_v1_admin_musicbots_validate',
        requirements: ['id' => '\\d+'],
        methods: ['POST'],
    )]
    public function validate(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireAdmin($request);
        $instance = $this->findInstance($id);

        $result = $this->installerService->validate($instance, $admin);

        return new JsonResponse(['data' => $result], 202);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function requireAdmin(Request $request): User
    {
        $user = $request->attributes->get('_user');
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Authentication required.');
        }
        if ($user->getType() !== UserType::Admin) {
            throw new AccessDeniedHttpException('Admin access required.');
        }
        return $user;
    }

    private function findInstance(int $id): MusicbotInstance
    {
        $instance = $this->instanceRepository->find($id);
        if ($instance === null) {
            throw new NotFoundHttpException(sprintf('Musicbot instance %d not found.', $id));
        }
        return $instance;
    }

    /** @return array<string, mixed> */
    private function parseOptions(Request $request): array
    {
        if (!$request->getContent()) {
            return [];
        }
        try {
            return (array) $request->toArray();
        } catch (\Throwable) {
            return [];
        }
    }
}
