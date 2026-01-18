<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceScheduleAction;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\InstanceRepository;
use App\Repository\InstanceScheduleRepository;
use App\Repository\JobLogRepository;
use App\Repository\JobRepository;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepository;
use App\Repository\ServerSftpAccessRepository;
use App\Module\Core\Application\AppSettingsService;
use App\Module\Gameserver\Application\InstanceInstallService;
use App\Module\Core\Application\JobPayloadMasker;
use App\Module\Gameserver\Application\MinecraftCatalogService;
use App\Module\Core\Application\SetupChecker;
use App\View\CustomerServerView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/kunden/servers')]
final class CustomerServerPanelController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly InstanceScheduleRepository $instanceScheduleRepository,
        private readonly PortBlockRepository $portBlockRepository,
        private readonly JobRepository $jobRepository,
        private readonly JobLogRepository $jobLogRepository,
        private readonly JobPayloadMasker $jobPayloadMasker,
        private readonly AppSettingsService $appSettingsService,
        private readonly ServerSftpAccessRepository $serverSftpAccessRepository,
        private readonly MinecraftCatalogService $minecraftCatalogService,
        private readonly SetupChecker $setupChecker,
        private readonly InstanceInstallService $instanceInstallService,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_server_panel_list', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $instances = $this->instanceRepository->findByCustomer($customer);
        $statusFilter = strtolower(trim((string) $request->query->get('status', '')));

        $portBlocks = $this->portBlockRepository->findByInstances($instances);
        $portBlockIndex = [];
        foreach ($portBlocks as $portBlock) {
            $assignedInstance = $portBlock->getInstance();
            if ($assignedInstance !== null) {
                $portBlockIndex[$assignedInstance->getId()] = $portBlock;
            }
        }

        $latestActions = $this->buildLatestJobIndex($instances);

        $servers = [];
        foreach ($instances as $instance) {
            $view = $this->buildServerView($instance, $portBlockIndex[$instance->getId()] ?? null, $latestActions);
            if ($statusFilter !== '' && $view->status !== $statusFilter) {
                continue;
            }
            $servers[] = $view;
        }

        return new Response($this->twig->render('customer/infrastructure/gameservers/index.html.twig', [
            'servers' => $servers,
            'activeNav' => 'gameservers',
            'statusFilter' => $statusFilter,
        ]));
    }

    #[Route(path: '/{id}', name: 'customer_server_panel_detail', methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $portBlock = $this->portBlockRepository->findByInstance($instance);
        $latestActions = $this->buildLatestJobIndex([$instance]);
        $server = $this->buildServerView($instance, $portBlock, $latestActions);
        $activeTab = $this->resolveTab((string) $request->query->get('tab', 'overview'));

        $updateSchedule = $this->instanceScheduleRepository->findOneByInstanceAndAction($instance, InstanceScheduleAction::Update);
        $updateScheduleView = $updateSchedule === null ? null : [
            'cron_expression' => $updateSchedule->getCronExpression(),
            'time_zone' => $updateSchedule->getTimeZone() ?? 'UTC',
            'enabled' => $updateSchedule->isEnabled(),
        ];

        $timeline = $this->buildJobTimeline($instance, 15);
        $logSnapshot = $this->buildLogSnapshot($instance, 200);

        return new Response($this->twig->render('customer/servers/server_detail.html.twig', [
            'server' => $server,
            'instance' => $instance,
            'activeNav' => 'servers',
            'activeTab' => $activeTab,
            'tabs' => $this->buildTabs($instance->getId() ?? 0),
            'updateSchedule' => $updateScheduleView,
            'timeline' => $timeline,
            'logSnapshot' => $logSnapshot,
            'sftp' => $this->normalizeSftpAccess($instance),
            'setupWizard' => $this->buildSetupWizardForm($instance),
            'minecraftCatalog' => $this->minecraftCatalogService->getUiCatalog(),
        ]));
    }

    #[Route(path: '/{id}/activity', name: 'customer_server_panel_activity', methods: ['GET'])]
    public function activity(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $timeline = $this->buildJobTimeline($instance, 15);

        return new Response($this->twig->render('customer/servers/_activity_timeline.html.twig', [
            'timeline' => $timeline,
        ]));
    }

    #[Route(path: '/{id}/logs/download', name: 'customer_server_panel_logs_download', methods: ['GET'])]
    public function downloadLogs(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $logSnapshot = $this->buildLogSnapshot($instance, 200);

        $lines = array_map(static fn (array $entry): string => sprintf(
            '[%s] %s',
            $entry['created_at']->format('Y-m-d H:i:s'),
            $entry['message'],
        ), $logSnapshot['entries']);

        $content = $lines === [] ? 'No log data available.' : implode("\n", $lines);

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => sprintf('attachment; filename="server-%d-logs.txt"', $instance->getId()),
        ]);
    }

    #[Route(path: '/{id}/logs/stream', name: 'customer_server_panel_logs_stream', methods: ['GET'])]
    public function streamLogs(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $instanceJobs = $this->findInstanceJobs($instance);
        $latestJob = $this->findLatestJob($instance);

        if ($latestJob === null || $instanceJobs === []) {
            return new JsonResponse([
                'job' => null,
                'logs' => [],
            ]);
        }

        $afterIdParam = $request->query->get('afterId');
        $afterId = is_numeric($afterIdParam) ? (int) $afterIdParam : null;

        $logs = $afterId !== null
            ? $this->jobLogRepository->findByJobsAfterId($instanceJobs, $afterId)
            : array_reverse($this->jobLogRepository->findLastByJobs($instanceJobs, 200));

        $entries = array_map(fn ($log) => [
            'id' => $log->getId(),
            'message' => $this->jobPayloadMasker->maskText($log->getMessage()),
            'progress' => $log->getProgress(),
            'created_at' => $log->getCreatedAt()->format(DATE_ATOM),
        ], $logs);

        return new JsonResponse([
            'job' => [
                'id' => $latestJob->getId(),
                'type' => $latestJob->getType(),
                'label' => $this->formatJobType($latestJob->getType()),
                'status' => $latestJob->getStatus()->value,
                'created_at' => $latestJob->getCreatedAt()->format(DATE_ATOM),
                'updated_at' => $latestJob->getUpdatedAt()->format(DATE_ATOM),
            ],
            'logs' => $entries,
        ]);
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    private function findCustomerInstance(User $customer, int $id): Instance
    {
        $instance = $this->instanceRepository->find($id);
        if ($instance === null) {
            throw new NotFoundHttpException('Instance not found.');
        }

        if ($instance->getCustomer()->getId() !== $customer->getId()) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        return $instance;
    }

    private function resolveTab(string $tab): string
    {
        $allowed = ['overview', 'files', 'config', 'logs', 'activity'];
        $normalized = strtolower(trim($tab));

        return in_array($normalized, $allowed, true) ? $normalized : 'overview';
    }

    private function buildServerView(Instance $instance, ?\App\Module\Ports\Domain\Entity\PortBlock $portBlock, array $latestActions): CustomerServerView
    {
        $connection = $this->buildConnectionData($instance, $portBlock);
        $status = $this->mapInstanceStatus($instance->getStatus());
        $missingFields = $this->buildSetupMissingFields($instance);
        $setupReady = $missingFields === [] && $this->setupChecker->getSetupStatus($instance)['is_ready'];
        $installStatus = $this->instanceInstallService->getInstallStatus($instance);
        $lastAction = $latestActions[$instance->getId()] ?? null;
        $instanceRoot = rtrim($this->appSettingsService->getInstanceBaseDir(), '/') . '/' . $instance->getId();
        $lastError = $instance->getDiskScanError();
        $lastError = is_string($lastError) && trim($lastError) !== '' ? $lastError : null;

        return new CustomerServerView(
            $instance->getId() ?? 0,
            $instance->getTemplate()->getDisplayName(),
            $instance->getTemplate()->getGameKey(),
            $status,
            $instance->getNode()->getLastSeenAt(),
            $lastAction['label'] ?? null,
            $lastAction['created_at'] ?? null,
            $instanceRoot,
            $connection['assigned_ports'],
            $connection['address'],
            $connection['quick_connect'],
            $missingFields,
            !$setupReady,
            $instance->getCurrentVersion(),
            $instance->getLockedVersion(),
            $instance->getNode()->getName(),
            $instance->getNode()->getId(),
            null,
            $lastError,
            $installStatus['is_ready'] ?? false,
            $installStatus['error_code'] ?? null,
        );
    }

    private function normalizeSftpAccess(Instance $instance): array
    {
        $access = $this->serverSftpAccessRepository->findOneByServer($instance);

        return [
            'enabled' => $access?->isEnabled() ?? false,
            'username' => $access?->getUsername() ?? $this->buildSftpUsername($instance),
            'host' => $this->resolveSftpHost($instance),
            'port' => $this->resolveSftpPort($instance),
            'keys_count' => $access ? count($access->getKeys()) : 0,
            'password_set_at' => $access?->getPasswordSetAt(),
        ];
    }

    private function buildSftpUsername(Instance $instance): string
    {
        return sprintf('gs_%d', $instance->getId());
    }

    private function resolveSftpHost(Instance $instance): ?string
    {
        $metadata = $instance->getNode()->getMetadata();
        $host = is_array($metadata) ? ($metadata['sftp_host'] ?? null) : null;
        if (is_string($host) && $host !== '') {
            return $host;
        }

        $lastIp = $instance->getNode()->getLastHeartbeatIp();
        if ($lastIp !== null && $lastIp !== '') {
            return $lastIp;
        }

        $host = $this->appSettingsService->getSftpHost();
        if (is_string($host) && $host !== '') {
            return $host;
        }

        return null;
    }

    private function resolveSftpPort(Instance $instance): int
    {
        $metadata = $instance->getNode()->getMetadata();
        $port = is_array($metadata) ? ($metadata['sftp_port'] ?? null) : null;
        if (is_numeric($port)) {
            return max(1, (int) $port);
        }

        return $this->appSettingsService->getSftpPort();
    }

    private function mapInstanceStatus(InstanceStatus $status): string
    {
        return match ($status) {
            InstanceStatus::PendingSetup => 'pending_setup',
            InstanceStatus::Provisioning => 'provisioning',
            InstanceStatus::Running => 'running',
            InstanceStatus::Stopped => 'stopped',
            default => 'unknown',
        };
    }

    private function buildSetupMissingFields(Instance $instance): array
    {
        $status = $this->setupChecker->getSetupStatus($instance);
        $requirements = $this->setupChecker->getCustomerRequirements($instance->getTemplate());
        $missingKeys = [];

        foreach ($status['missing'] as $entry) {
            $missingKeys[$entry['key']] = true;
        }

        $labels = [];
        foreach (array_merge($requirements['vars'], $requirements['secrets']) as $entry) {
            if (isset($missingKeys[$entry['key']])) {
                $labels[] = $entry['label'];
            }
        }

        return $labels;
    }

    /**
     * @return array<int, array{label: string, created_at: \DateTimeImmutable}>
     */
    private function buildLatestJobIndex(array $instances): array
    {
        if ($instances === []) {
            return [];
        }

        $latestJobs = [];
        $instanceIds = array_map(static fn (Instance $instance): int => $instance->getId() ?? 0, $instances);
        $instanceIds = array_filter($instanceIds, static fn (int $id): bool => $id > 0);
        $jobs = $this->jobRepository->findLatest(200);

        foreach ($jobs as $job) {
            $instanceId = $this->getInstanceIdFromJob($job);
            if ($instanceId === null) {
                continue;
            }
            if (!in_array($instanceId, $instanceIds, true)) {
                continue;
            }

            if (!isset($latestJobs[$instanceId])) {
                $latestJobs[$instanceId] = [
                    'label' => $this->formatJobType($job->getType()),
                    'created_at' => $job->getCreatedAt(),
                ];
            }
        }

        return $latestJobs;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildJobTimeline(Instance $instance, int $limit): array
    {
        $jobs = $this->jobRepository->findLatest(200);
        $timeline = [];

        foreach ($jobs as $job) {
            if ((string) $this->getInstanceIdFromJob($job) !== (string) $instance->getId()) {
                continue;
            }

            $result = $job->getResult();
            $logs = $this->jobLogRepository->findByJob($job);
            $resultOutput = $result?->getOutput();
            $maskedOutput = $result ? $this->jobPayloadMasker->maskValue($resultOutput) : null;
            $errorCode = is_array($resultOutput) ? ($resultOutput['error_code'] ?? null) : null;
            $errorCode = is_string($errorCode) && $errorCode !== '' ? $errorCode : null;

            $timeline[] = [
                'id' => $job->getId(),
                'label' => $this->formatJobType($job->getType()),
                'status' => $job->getStatus()->value,
                'created_at' => $job->getCreatedAt(),
                'updated_at' => $job->getUpdatedAt(),
                'result_status' => $result?->getStatus()->value,
                'result_output' => $maskedOutput,
                'error_code' => $errorCode,
                'logs' => array_map(fn ($log) => [
                    'message' => $this->jobPayloadMasker->maskText($log->getMessage()),
                    'progress' => $log->getProgress(),
                    'created_at' => $log->getCreatedAt(),
                ], $logs),
            ];

            if (count($timeline) >= $limit) {
                break;
            }
        }

        return $timeline;
    }

    /**
     * @return array{job_id: string|null, job_label: string|null, job_status: string|null, entries: array<int, array{id: int, message: string, created_at: \DateTimeImmutable}>}
     */
    private function buildLogSnapshot(Instance $instance, int $limit): array
    {
        $latestJob = $this->findLatestJob($instance);
        $instanceJobs = $this->findInstanceJobs($instance);

        if ($latestJob === null || $instanceJobs === []) {
            return [
                'job_id' => null,
                'job_label' => null,
                'job_status' => null,
                'entries' => [],
            ];
        }

        $logs = $this->jobLogRepository->findLastByJobs($instanceJobs, $limit);
        $entries = array_reverse(array_map(fn ($log) => [
            'id' => $log->getId(),
            'message' => $this->jobPayloadMasker->maskText($log->getMessage()),
            'created_at' => $log->getCreatedAt(),
        ], $logs));

        return [
            'job_id' => $latestJob->getId(),
            'job_label' => $this->formatJobType($latestJob->getType()),
            'job_status' => $latestJob->getStatus()->value,
            'entries' => $entries,
        ];
    }

    private function findLatestJob(Instance $instance): ?Job
    {
        $jobs = $this->findInstanceJobs($instance);
        $preferredTypes = $this->getPreferredLogJobTypes();

        foreach ($jobs as $job) {
            if (!in_array($job->getType(), $preferredTypes, true)) {
                continue;
            }
            if ((string) $this->getInstanceIdFromJob($job) === (string) $instance->getId()) {
                return $job;
            }
        }

        foreach ($jobs as $job) {
            if ((string) $this->getInstanceIdFromJob($job) === (string) $instance->getId()) {
                return $job;
            }
        }

        return null;
    }

    /**
     * @return Job[]
     */
    private function findInstanceJobs(Instance $instance): array
    {
        $jobs = $this->jobRepository->findLatest(200);
        $instanceJobs = [];

        foreach ($jobs as $job) {
            if ((string) $this->getInstanceIdFromJob($job) === (string) $instance->getId()) {
                $instanceJobs[] = $job;
            }
        }

        return $instanceJobs;
    }

    /**
     * @return list<string>
     */
    private function getPreferredLogJobTypes(): array
    {
        return [
            'sniper.install',
            'sniper.update',
            'instance.reinstall',
            'instance.start',
            'instance.restart',
            'instance.stop',
        ];
    }

    private function getInstanceIdFromJob(Job $job): ?int
    {
        $payload = $job->getPayload();
        $instanceId = $payload['instance_id'] ?? null;

        return is_numeric($instanceId) ? (int) $instanceId : null;
    }

    private function formatJobType(string $type): string
    {
        return match ($type) {
            'sniper.install' => 'Install',
            'instance.start' => 'Start',
            'instance.stop' => 'Stop',
            'instance.restart' => 'Restart',
            'instance.reinstall' => 'Reinstall',
            'instance.backup.create' => 'Backup',
            'instance.backup.restore' => 'Restore',
            'instance.addon.install' => 'Addon Install',
            'instance.addon.update' => 'Addon Update',
            'instance.addon.remove' => 'Addon Remove',
            default => $type,
        };
    }

    private function buildTabs(int $instanceId): array
    {
        return [
            [
                'key' => 'overview',
                'label' => 'Overview',
                'href' => sprintf('/kunden/servers/%d?tab=overview', $instanceId),
            ],
            [
                'key' => 'files',
                'label' => 'Files',
                'href' => sprintf('/kunden/servers/%d?tab=files', $instanceId),
            ],
            [
                'key' => 'config',
                'label' => 'Config',
                'href' => sprintf('/kunden/servers/%d?tab=config', $instanceId),
            ],
            [
                'key' => 'logs',
                'label' => 'Logs',
                'href' => sprintf('/kunden/servers/%d?tab=logs', $instanceId),
            ],
            [
                'key' => 'activity',
                'label' => 'Activity',
                'href' => sprintf('/kunden/servers/%d?tab=activity', $instanceId),
            ],
        ];
    }

    /**
     * @return array{vars: array<int, array<string, mixed>>, secrets: array<int, array<string, mixed>>}
     */
    private function buildSetupWizardForm(Instance $instance): array
    {
        $requirements = $this->setupChecker->getCustomerRequirements($instance->getTemplate());
        $setupVars = $instance->getSetupVars();
        $setupSecrets = $instance->getSetupSecrets();

        $varEntries = array_map(static function (array $entry) use ($setupVars): array {
            $key = $entry['key'];

            return [
                'key' => $key,
                'label' => $entry['label'],
                'type' => $entry['type'],
                'required' => $entry['required'],
                'helptext' => $entry['helptext'],
                'value' => $setupVars[$key] ?? '',
            ];
        }, $requirements['vars']);

        $secretEntries = array_map(static function (array $entry) use ($setupSecrets): array {
            $key = $entry['key'];

            return [
                'key' => $key,
                'label' => $entry['label'],
                'type' => $entry['type'],
                'required' => $entry['required'],
                'helptext' => $entry['helptext'],
                'is_set' => array_key_exists($key, $setupSecrets),
            ];
        }, $requirements['secrets']);

        return [
            'vars' => $varEntries,
            'secrets' => $secretEntries,
        ];
    }

    private function buildConnectionData(Instance $instance, ?\App\Module\Ports\Domain\Entity\PortBlock $portBlock): array
    {
        $host = $instance->getNode()->getLastHeartbeatIp();
        $requiredPorts = $instance->getTemplate()->getRequiredPorts();
        $assignedPorts = [];
        $primaryPort = null;

        if ($portBlock !== null) {
            $ports = $portBlock->getPorts();

            foreach ($requiredPorts as $index => $definition) {
                if (!isset($ports[$index])) {
                    continue;
                }

                $label = (string) ($definition['name'] ?? 'port');
                $protocol = (string) ($definition['protocol'] ?? 'udp');
                $assignedPorts[] = [
                    'label' => sprintf('%s/%s', $label, $protocol),
                    'port' => $ports[$index],
                ];

                if ($primaryPort === null) {
                    $primaryPort = $ports[$index];
                }
            }

            if ($primaryPort === null && isset($ports[0])) {
                $primaryPort = $ports[0];
            }
        }

        $address = $host !== null && $primaryPort !== null ? sprintf('%s:%d', $host, $primaryPort) : null;

        return [
            'host' => $host,
            'address' => $address,
            'quick_connect' => $address !== null ? sprintf('steam://connect/%s', $address) : null,
            'assigned_ports' => $assignedPorts,
        ];
    }
}
