<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\JobStatus;
use App\Module\Core\Domain\Enum\TicketStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\AgentRepository;
use App\Repository\DatabaseRepository;
use App\Repository\InstanceRepository;
use App\Repository\JobRepository;
use App\Repository\MailboxRepository;
use App\Repository\MetricSampleRepository;
use App\Repository\TicketRepository;
use App\Repository\Ts3VirtualServerRepository;
use App\Repository\Ts6VirtualServerRepository;
use App\Repository\UserRepository;
use App\Repository\WebspaceRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin')]
final class AdminDashboardController
{
    public function __construct(
        private readonly JobRepository $jobRepository,
        private readonly AgentRepository $agentRepository,
        private readonly UserRepository $userRepository,
        private readonly InstanceRepository $instanceRepository,
        private readonly WebspaceRepository $webspaceRepository,
        private readonly DatabaseRepository $databaseRepository,
        private readonly MailboxRepository $mailboxRepository,
        private readonly Ts3VirtualServerRepository $ts3VirtualServerRepository,
        private readonly Ts6VirtualServerRepository $ts6VirtualServerRepository,
        private readonly TicketRepository $ticketRepository,
        private readonly MetricSampleRepository $metricSampleRepository,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_dashboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $jobs = $this->jobRepository->findLatest(8);
        $agents = $this->agentRepository->findBy([], ['updatedAt' => 'DESC']);
        $agentSummary = $this->buildAgentSummary($agents);
        $jobSummary = [
            'total' => $this->jobRepository->countAll(),
            'running' => $this->jobRepository->countByStatus(JobStatus::Running),
            'queued' => $this->jobRepository->countByStatus(JobStatus::Queued),
            'failed' => $this->jobRepository->countByStatus(JobStatus::Failed),
        ];
        $overview = [
            'customers' => $this->userRepository->count(['type' => UserType::Customer->value]),
            'instances' => $this->instanceRepository->count([]),
            'tickets_open' => $this->ticketRepository->count(['status' => TicketStatus::Open]),
            'tickets_total' => $this->ticketRepository->count([]),
        ];
        $moduleSummary = [
            'webspaces' => $this->webspaceRepository->countProvisioned(),
            'databases' => $this->databaseRepository->count([]),
            'games' => $overview['instances'],
            'mailboxes' => $this->mailboxRepository->count([]),
            'voiceServers' => $this->ts3VirtualServerRepository->count(['archivedAt' => null])
                + $this->ts6VirtualServerRepository->count(['archivedAt' => null]),
        ];
        $primaryAgent = $agents[0] ?? null;
        $cpuSeries = [];
        $memorySeries = [];
        $bandwidthSeries = [];
        $bandwidthValue = null;
        if ($primaryAgent !== null) {
            $since = (new \DateTimeImmutable())->sub(new \DateInterval('PT2H'));
            $metricSeries = $this->metricSampleRepository->findSeriesForAgentSince($primaryAgent, $since);
            if (count($metricSeries) > 30) {
                $metricSeries = array_slice($metricSeries, -30);
            }
            $cpuSeries = $this->extractSeriesValues($metricSeries, 'cpuPercent');
            $memorySeries = $this->extractSeriesValues($metricSeries, 'memoryPercent');
            [$bandwidthSeries, $bandwidthValue] = $this->buildBandwidthSeries($metricSeries);
            if ($bandwidthValue !== null) {
                $bandwidthValue = $this->formatBandwidth($bandwidthValue);
            }
        }

        return new Response($this->twig->render('admin/dashboard/index.html.twig', [
            'activeNav' => 'dashboard',
            'jobSummary' => $jobSummary,
            'agentSummary' => $agentSummary,
            'overview' => $overview,
            'moduleSummary' => $moduleSummary,
            'jobs' => $this->normalizeJobs($jobs),
            'nodes' => $this->normalizeAgents(array_slice($agents, 0, 6)),
            'cpuSeries' => $cpuSeries,
            'memorySeries' => $memorySeries,
            'bandwidthSeries' => $bandwidthSeries,
            'bandwidthValue' => $bandwidthValue,
        ]));
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        return $actor instanceof User && $actor->isAdmin();
    }

    private function buildAgentSummary(array $agents): array
    {
        $summary = [
            'total' => count($agents),
            'online' => 0,
            'degraded' => 0,
            'offline' => 0,
        ];

        foreach ($agents as $agent) {
            $status = $this->resolveAgentStatus($agent->getLastHeartbeatAt());
            $summary[$status]++;
        }

        return $summary;
    }

    private function resolveAgentStatus(?\DateTimeImmutable $lastHeartbeatAt): string
    {
        if ($lastHeartbeatAt === null) {
            return 'offline';
        }

        $now = new \DateTimeImmutable();
        if ($lastHeartbeatAt >= $now->sub(new \DateInterval('PT5M'))) {
            return 'online';
        }

        if ($lastHeartbeatAt >= $now->sub(new \DateInterval('PT30M'))) {
            return 'degraded';
        }

        return 'offline';
    }

    private function normalizeAgents(array $agents): array
    {
        return array_map(function ($agent): array {
            $status = $this->resolveAgentStatus($agent->getLastHeartbeatAt());
            $stats = $agent->getLastHeartbeatStats() ?? [];
            $metrics = is_array($stats['metrics'] ?? null) ? $stats['metrics'] : [];
            $cpu = $this->formatPercent(
                $this->extractMetricPercent($metrics, 'cpu')
                ?? $this->extractMetricPercent($stats, 'cpu'),
            );
            $memory = $this->formatPercent(
                $this->extractMetricPercent($metrics, 'memory')
                ?? $this->extractMetricPercent($stats, 'memory'),
            );
            $queue = $this->extractMetricValue($metrics, 'queue') ?? $this->extractMetricValue($stats, 'queue');

            return [
                'id' => $agent->getId(),
                'name' => $agent->getName() ?? 'Unnamed agent',
                'status' => $status,
                'lastHeartbeatAt' => $agent->getLastHeartbeatAt(),
                'lastHeartbeatIp' => $agent->getLastHeartbeatIp(),
                'lastHeartbeatVersion' => $agent->getLastHeartbeatVersion(),
                'cpu' => $cpu,
                'memory' => $memory,
                'queue' => $queue,
            ];
        }, $agents);
    }

    private function extractMetricPercent(array $metrics, string $key): ?float
    {
        $metric = $metrics[$key] ?? null;
        if (is_array($metric)) {
            $value = $metric['percent'] ?? null;
            return is_numeric($value) ? (float) $value : null;
        }

        return is_numeric($metric) ? (float) $metric : null;
    }

    private function extractMetricValue(array $metrics, string $key): ?string
    {
        $metric = $metrics[$key] ?? null;
        if (is_scalar($metric)) {
            $value = trim((string) $metric);
            return $value !== '' ? $value : null;
        }

        return null;
    }

    private function formatPercent(?float $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return rtrim(rtrim(sprintf('%.1f', $value), '0'), '.') . '%';
    }

    /**
     * @param array<int, array{recordedAt: \DateTimeImmutable, cpuPercent: ?float, memoryPercent: ?float, diskPercent: ?float, netBytesSent: ?int, netBytesRecv: ?int, payload: ?array}> $series
     * @return float[]
     */
    private function extractSeriesValues(array $series, string $key): array
    {
        $values = [];
        foreach ($series as $row) {
            $value = $row[$key] ?? null;
            if (is_numeric($value)) {
                $values[] = (float) $value;
            }
        }

        return $values;
    }

    /**
     * @param array<int, array{recordedAt: \DateTimeImmutable, cpuPercent: ?float, memoryPercent: ?float, diskPercent: ?float, netBytesSent: ?int, netBytesRecv: ?int, payload: ?array}> $series
     * @return array{0: float[], 1: ?float}
     */
    private function buildBandwidthSeries(array $series): array
    {
        $values = [];
        $latest = null;
        $previous = null;

        foreach ($series as $row) {
            $sent = $row['netBytesSent'] ?? null;
            $recv = $row['netBytesRecv'] ?? null;
            if (!is_numeric($sent) || !is_numeric($recv)) {
                $previous = null;
                continue;
            }

            $total = (int) $sent + (int) $recv;
            if ($previous !== null) {
                $deltaBytes = $total - $previous['total'];
                $deltaSeconds = $row['recordedAt']->getTimestamp() - $previous['recordedAt']->getTimestamp();
                if ($deltaBytes >= 0 && $deltaSeconds > 0) {
                    $mbit = ($deltaBytes * 8) / ($deltaSeconds * 1024 * 1024);
                    $values[] = $mbit;
                    $latest = $mbit;
                }
            }

            $previous = [
                'total' => $total,
                'recordedAt' => $row['recordedAt'],
            ];
        }

        return [$values, $latest];
    }

    private function formatBandwidth(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.1f', $value), '0'), '.');
        return $formatted . ' Mbit/s';
    }

    private function normalizeJobs(array $jobs): array
    {
        return array_map(static function ($job): array {
            return [
                'id' => $job->getId(),
                'type' => $job->getType(),
                'status' => $job->getStatus()->value,
                'createdAt' => $job->getCreatedAt(),
                'lockedBy' => $job->getLockedBy(),
                'lockedAt' => $job->getLockedAt(),
            ];
        }, $jobs);
    }
}
