<?php

declare(strict_types=1);

namespace App\Module\Core\UI\Controller;

use App\Infrastructure\Config\DbConfigProvider;
use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\FileServiceClient;
use App\Module\Core\Application\UpdateJobService;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Setup\Application\InstallerService;
use App\Repository\AgentRepository;
use App\Repository\InstanceRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SystemHealthController
{
    public function __construct(
        private readonly InstallerService $installerService,
        private readonly DbConfigProvider $configProvider,
        private readonly AppSettingsService $settingsService,
        private readonly AgentRepository $agentRepository,
        private readonly InstanceRepository $instanceRepository,
        private readonly FileServiceClient $fileServiceClient,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly UpdateJobService $updateJobService,
    ) {
    }

    #[Route(path: '/system/health', name: 'system_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        $dbConfigured = $this->configProvider->exists();
        $sftpConfigured = false;
        $appEnv = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'unknown';
        $appDebug = $_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? '0';
        $setupStateDir = $this->installerService->getSetupStateDirPath();
        $agentHealth = ['status' => 'skipped', 'message' => 'db_not_configured'];
        $filesvcHealth = ['status' => 'skipped', 'message' => 'db_not_configured'];
        $migrationStatus = ['pending' => null, 'executedUnavailable' => null, 'error' => null];
        $latestJob = $this->updateJobService->getLatestJob();

        if ($dbConfigured) {
            $sftpHost = $this->settingsService->getSftpHost();
            $sftpConfigured = $sftpHost !== null && $sftpHost !== '';
            $agentHealth = $this->probeAgentHealth();
            $filesvcHealth = $this->probeFilesvcHealth();
            $migrationStatus = $this->updateJobService->getMigrationStatus();
        }

        $versionInfo = $this->updateJobService->getVersionInfo();
        $recoveryRequired = $migrationStatus['pending'] !== null && $migrationStatus['pending'] > 0;

        return new JsonResponse([
            'app_env' => $appEnv,
            'app_debug' => filter_var($appDebug, FILTER_VALIDATE_BOOL),
            'setup_locked' => $this->installerService->isLocked(),
            'setup_state_dir' => [
                'path' => $setupStateDir,
                'writable' => is_dir($setupStateDir) && is_writable($setupStateDir),
            ],
            'encryption_key' => [
                'readable' => $this->configProvider->isKeyReadable(),
                'path' => $this->configProvider->getKeyPath(),
            ],
            'database_config' => [
                'status' => $dbConfigured ? 'configured' : 'missing',
                'present' => $dbConfigured,
                'path' => $this->configProvider->getConfigPath(),
            ],
            'sftp_config' => [
                'host_configured' => $sftpConfigured,
            ],
            'core_version' => $versionInfo,
            'recovery_required' => $recoveryRequired,
            'update_jobs' => [
                'latest' => $latestJob ? [
                    'id' => $latestJob['id'] ?? null,
                    'type' => $latestJob['type'] ?? null,
                    'status' => $latestJob['status'] ?? null,
                    'createdAt' => $latestJob['createdAt'] ?? null,
                    'startedAt' => $latestJob['startedAt'] ?? null,
                    'finishedAt' => $latestJob['finishedAt'] ?? null,
                    'exitCode' => $latestJob['exitCode'] ?? null,
                ] : null,
            ],
            'migrations' => [
                'pending' => $migrationStatus['pending'],
                'executed_unavailable' => $migrationStatus['executedUnavailable'],
                'error' => $migrationStatus['error'],
            ],
            'agent_health' => $agentHealth,
            'filesvc_health' => $filesvcHealth,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function probeAgentHealth(): array
    {
        try {
            $agent = $this->agentRepository
                ->createQueryBuilder('agent')
                ->where('agent.serviceBaseUrl IS NOT NULL')
                ->orderBy('agent.lastHeartbeatAt', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        } catch (\Throwable $exception) {
            $this->logger->warning('agent.health_probe_failed', [
                'exception' => $exception,
            ]);
            return [
                'status' => 'error',
                'message' => 'agent_probe_failed',
            ];
        }

        if (!$agent instanceof Agent) {
            return [
                'status' => 'missing',
                'message' => 'no_agent_with_service_url',
            ];
        }

        $baseUrl = $agent->getAgentBaseUrl();
        if ($baseUrl === '') {
            return [
                'status' => 'missing',
                'message' => 'agent_base_url_missing',
                'agent_id' => $agent->getId(),
            ];
        }

        $healthUrl = rtrim($baseUrl, '/') . '/health';
        try {
            $response = $this->httpClient->request('GET', $healthUrl, [
                'timeout' => 3,
                'max_duration' => 3,
            ]);
            $status = $response->getStatusCode();
            return [
                'status' => $status >= 200 && $status < 300 ? 'ok' : 'bad_status',
                'agent_id' => $agent->getId(),
                'status_code' => $status,
            ];
        } catch (TimeoutExceptionInterface $exception) {
            return [
                'status' => 'timeout',
                'agent_id' => $agent->getId(),
            ];
        } catch (TransportExceptionInterface $exception) {
            return [
                'status' => 'unreachable',
                'agent_id' => $agent->getId(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function probeFilesvcHealth(): array
    {
        try {
            $instance = $this->instanceRepository
                ->createQueryBuilder('instance')
                ->orderBy('instance.id', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        } catch (\Throwable $exception) {
            $this->logger->warning('filesvc.health_query_failed', [
                'exception' => $exception,
            ]);
            return [
                'status' => 'error',
                'message' => 'filesvc_probe_failed',
            ];
        }

        if ($instance === null) {
            return [
                'status' => 'missing',
                'message' => 'no_instances_available',
            ];
        }

        try {
            $ping = $this->fileServiceClient->ping($instance);
        } catch (\Throwable $exception) {
            $this->logger->warning('filesvc.health_probe_failed', [
                'instance_id' => $instance->getId(),
                'exception' => $exception,
            ]);
            return [
                'status' => 'error',
                'instance_id' => $instance->getId(),
                'message' => 'filesvc_probe_failed',
            ];
        }

        $authOk = false;
        if ($ping['ok']) {
            try {
                $this->fileServiceClient->list($instance, '');
                $authOk = true;
            } catch (\Throwable $exception) {
                $this->logger->warning('filesvc.auth_probe_failed', [
                    'instance_id' => $instance->getId(),
                    'exception' => $exception,
                ]);
            }
        }

        return [
            'status' => $ping['ok'] ? 'ok' : 'error',
            'status_code' => $ping['status_code'],
            'auth_ok' => $authOk,
            'instance_id' => $instance->getId(),
        ];
    }
}
