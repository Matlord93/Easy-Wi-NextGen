<?php

declare(strict_types=1);

namespace App\Module\Musicbot\UI\Controller\Api;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcherInterface;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Musicbot\Application\MusicbotDiagnosticBundleService;
use App\Module\Musicbot\Application\MusicbotHealthService;
use App\Module\Musicbot\Application\MusicbotPermissionService;
use App\Module\Musicbot\Application\MusicbotQuotaService;
use App\Module\Musicbot\Application\MusicbotRepairService;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Enum\MusicbotPermission;
use App\Module\Musicbot\Domain\Exception\MusicbotPermissionDeniedException;
use App\Module\Musicbot\Domain\Exception\MusicbotQuotaExceededException;
use App\Repository\MusicbotInstanceRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class MusicbotHealthApiController
{
    public function __construct(
        private readonly MusicbotHealthService $healthService,
        private readonly MusicbotRepairService $repairService,
        private readonly MusicbotDiagnosticBundleService $bundleService,
        private readonly MusicbotInstanceRepository $instanceRepository,
        private readonly MusicbotQuotaService $quotaService,
        private readonly MusicbotPermissionService $permissionService,
        private readonly AgentJobDispatcherInterface $jobDispatcher,
    ) {
    }

    /**
     * GET /api/v1/customer/musicbots/{id}/health/status
     *
     * Returns the last known health status without triggering a new check.
     */
    #[Route(
        path: '/api/v1/customer/musicbots/{id}/health/status',
        name: 'api_v1_customer_musicbots_health_status',
        requirements: ['id' => '\\d+'],
        methods: ['GET'],
    )]
    public function customerStatus(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $this->assertPerm($customer, $instance, MusicbotPermission::View);
        $report = $this->healthService->check($instance, adminView: false);

        return new JsonResponse(['data' => $this->normalizeReportForCustomer($report)]);
    }

    /**
     * POST /api/v1/customer/musicbots/{id}/health/check
     *
     * Triggers a fresh health check job on the agent, then returns persisted state.
     */
    #[Route(
        path: '/api/v1/customer/musicbots/{id}/health/check',
        name: 'api_v1_customer_musicbots_health_check',
        requirements: ['id' => '\\d+'],
        methods: ['POST'],
    )]
    public function customerCheck(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $this->assertPerm($customer, $instance, MusicbotPermission::Restart);
        $job = $this->dispatchHealthCheckJob($instance, $customer);
        $report = $this->healthService->check($instance, adminView: false);

        return new JsonResponse([
            'data' => $this->normalizeReportForCustomer($report),
            'job_id' => $job->getId(),
        ], JsonResponse::HTTP_ACCEPTED);
    }

    /**
     * POST /api/v1/customer/musicbots/{id}/health/repair
     *
     * Triggers an allowed repair action.
     */
    #[Route(
        path: '/api/v1/customer/musicbots/{id}/health/repair',
        name: 'api_v1_customer_musicbots_health_repair',
        requirements: ['id' => '\\d+'],
        methods: ['POST'],
    )]
    public function customerRepair(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $this->assertPerm($customer, $instance, MusicbotPermission::Restart);
        $action = trim((string) ($request->toArray()['action'] ?? ''));
        if ($action === '') {
            return $this->error('action is required.', JsonResponse::HTTP_BAD_REQUEST);
        }
        try {
            $result = $this->repairService->repair($instance, $customer, $action);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_FORBIDDEN);
        }

        return new JsonResponse(['data' => $result], JsonResponse::HTTP_ACCEPTED);
    }

    /**
     * GET /api/v1/customer/musicbots/{id}/health/logs
     *
     * Returns recent runtime events / last error for customer view.
     */
    #[Route(
        path: '/api/v1/customer/musicbots/{id}/health/logs',
        name: 'api_v1_customer_musicbots_health_logs',
        requirements: ['id' => '\\d+'],
        methods: ['GET'],
    )]
    public function customerLogs(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $this->assertPerm($customer, $instance, MusicbotPermission::LogsView);
        $runtime = $instance->getRuntimePayload() ?? [];

        return new JsonResponse([
            'data' => [
                'last_error' => $instance->getLastError(),
                'status' => $instance->getStatus()->value,
                'updated_at' => $instance->getUpdatedAt()->format(\DateTimeInterface::ATOM),
                'recent_events' => $runtime['recent_events'] ?? [],
            ],
        ]);
    }

    // ── Admin endpoints ──────────────────────────────────────────────────────

    /**
     * GET /api/v1/admin/musicbots/{id}/health/status
     */
    #[Route(
        path: '/api/v1/admin/musicbots/{id}/health/status',
        name: 'api_v1_admin_musicbots_health_status',
        requirements: ['id' => '\\d+'],
        methods: ['GET'],
    )]
    public function adminStatus(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $instance = $this->findAdminInstance($id);
        $report = $this->healthService->check($instance, adminView: true);

        return new JsonResponse(['data' => $this->normalizeReportForAdmin($instance, $report)]);
    }

    /**
     * POST /api/v1/admin/musicbots/{id}/health/check
     */
    #[Route(
        path: '/api/v1/admin/musicbots/{id}/health/check',
        name: 'api_v1_admin_musicbots_health_check',
        requirements: ['id' => '\\d+'],
        methods: ['POST'],
    )]
    public function adminCheck(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireAdmin($request);
        $instance = $this->findAdminInstance($id);
        $job = $this->dispatchHealthCheckJob($instance, $admin);
        $report = $this->healthService->check($instance, adminView: true);

        return new JsonResponse([
            'data' => $this->normalizeReportForAdmin($instance, $report),
            'job_id' => $job->getId(),
        ], JsonResponse::HTTP_ACCEPTED);
    }

    /**
     * POST /api/v1/admin/musicbots/{id}/health/repair
     */
    #[Route(
        path: '/api/v1/admin/musicbots/{id}/health/repair',
        name: 'api_v1_admin_musicbots_health_repair',
        requirements: ['id' => '\\d+'],
        methods: ['POST'],
    )]
    public function adminRepair(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireAdmin($request);
        $instance = $this->findAdminInstance($id);
        $action = trim((string) ($request->toArray()['action'] ?? ''));
        if ($action === '') {
            return $this->error('action is required.', JsonResponse::HTTP_BAD_REQUEST);
        }
        try {
            $result = $this->repairService->repair($instance, $admin, $action);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_FORBIDDEN);
        }

        return new JsonResponse(['data' => $result], JsonResponse::HTTP_ACCEPTED);
    }

    /**
     * GET /api/v1/admin/musicbots/{id}/health/logs
     */
    #[Route(
        path: '/api/v1/admin/musicbots/{id}/health/logs',
        name: 'api_v1_admin_musicbots_health_logs',
        requirements: ['id' => '\\d+'],
        methods: ['GET'],
    )]
    public function adminLogs(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $instance = $this->findAdminInstance($id);
        $runtime = $instance->getRuntimePayload() ?? [];

        return new JsonResponse([
            'data' => [
                'last_error' => $instance->getLastError(),
                'status' => $instance->getStatus()->value,
                'updated_at' => $instance->getUpdatedAt()->format(\DateTimeInterface::ATOM),
                'install_path' => $instance->getInstallPath(),
                'service_name' => $instance->getServiceName(),
                'runtime_payload' => $runtime,
                'systemd_status' => $runtime['systemd_status'] ?? null,
                'journal_excerpt' => $runtime['journal_excerpt'] ?? null,
                'agent_payload' => $runtime['agent_payload'] ?? null,
                'control_socket_details' => $runtime['control_socket_details'] ?? null,
                'pulseaudio_details' => $runtime['pulseaudio_details'] ?? null,
                'teamspeak_bridge_details' => $runtime['teamspeak_bridge_details'] ?? null,
                'recent_events' => $runtime['recent_events'] ?? [],
            ],
        ]);
    }

    /**
     * GET /api/v1/admin/musicbots/{id}/health/diagnostic-bundle
     */
    #[Route(
        path: '/api/v1/admin/musicbots/{id}/health/diagnostic-bundle',
        name: 'api_v1_admin_musicbots_health_diagnostic_bundle',
        requirements: ['id' => '\\d+'],
        methods: ['GET'],
    )]
    public function adminDiagnosticBundle(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $instance = $this->findAdminInstance($id);
        $bundle = $this->bundleService->build($instance, $this->healthService);

        return new JsonResponse(['data' => $bundle]);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function assertPerm(User $customer, MusicbotInstance $instance, MusicbotPermission $permission): void
    {
        try {
            $this->permissionService->assertActionAllowed($customer, $instance, $permission);
        } catch (MusicbotPermissionDeniedException $e) {
            throw new AccessDeniedHttpException($e->getMessage(), $e);
        }
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new UnauthorizedHttpException('api', 'Unauthorized.');
        }
        try {
            $this->quotaService->assertApiAllowed($actor);
        } catch (MusicbotQuotaExceededException $e) {
            throw new AccessDeniedHttpException($e->getMessage(), $e);
        }

        return $actor;
    }

    private function requireAdmin(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        return $actor;
    }

    private function findCustomerInstance(int $id, User $customer): MusicbotInstance
    {
        $instance = $this->instanceRepository->findOneForCustomer($id, $customer);
        if (!$instance instanceof MusicbotInstance) {
            throw new NotFoundHttpException('Musicbot not found.');
        }

        return $instance;
    }

    private function findAdminInstance(int $id): MusicbotInstance
    {
        $instance = $this->instanceRepository->find($id);
        if (!$instance instanceof MusicbotInstance) {
            throw new NotFoundHttpException('Musicbot not found.');
        }

        return $instance;
    }

    private function dispatchHealthCheckJob(MusicbotInstance $instance, User $actor): \App\Module\AgentOrchestrator\Domain\Entity\AgentJob
    {
        return $this->jobDispatcher->dispatch($instance->getNode(), 'musicbot.health.check', [
            'instance_id' => (string) $instance->getId(),
            'service_name' => $instance->getServiceName(),
            'install_dir' => $instance->getInstallPath(),
            'install_path' => $instance->getInstallPath(),
            'actor_id' => (string) $actor->getId(),
        ]);
    }

    /** @param array<string, mixed> $report @return array<string, mixed> */
    private function normalizeReportForCustomer(array $report): array
    {
        $overall = $report['overall'];
        $checks = $report['checks'];

        $friendlyChecks = [];
        foreach ($checks as $key => $check) {
            $friendlyChecks[] = [
                'name' => $key,
                'status' => $check['status'],
                'message' => $check['message'],
                'recommended_action' => $check['recommended_action'],
                'auto_repair_available' => $check['auto_repair_available'],
                'repair_action' => $check['repair_action'] ?? null,
            ];
        }

        return [
            'overall_status' => $overall->value,
            'overall_label' => $overall->label(),
            'is_operational' => $overall->isOperational(),
            'checks' => $friendlyChecks,
            'checked_at' => $report['checked_at'],
        ];
    }

    /** @param array<string, mixed> $report @return array<string, mixed> */
    private function normalizeReportForAdmin(MusicbotInstance $instance, array $report): array
    {
        $runtime = $instance->getRuntimePayload() ?? [];
        $base = $this->normalizeReportForCustomer($report);

        return array_merge($base, [
            'checks_raw' => $report['checks'],
            'install_path' => $instance->getInstallPath(),
            'service_name' => $instance->getServiceName(),
            'systemd_status' => $runtime['systemd_status'] ?? null,
            'journal_excerpt' => $runtime['journal_excerpt'] ?? null,
            'runtime_payload' => $runtime,
            'agent_payload' => $runtime['agent_payload'] ?? null,
            'control_socket_details' => $runtime['control_socket_details'] ?? null,
            'pulseaudio_details' => $runtime['pulseaudio_details'] ?? null,
            'teamspeak_bridge_details' => $runtime['teamspeak_bridge_details'] ?? null,
            'allowed_repair_actions' => array_values($this->repairService->allowedActionsForActor($instance->getCustomer())),
        ]);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
