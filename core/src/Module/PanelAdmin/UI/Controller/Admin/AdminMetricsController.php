<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Http\RequestActor;
use App\Module\Core\Application\MetricsLiveBuffer;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\InstanceMetricSampleRepository;
use App\Repository\InstanceRepository;
use App\Repository\MetricSampleRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(path: '/admin/metrics')]
final class AdminMetricsController
{
    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly InstanceRepository $instanceRepository,
        private readonly MetricSampleRepository $metricSampleRepository,
        private readonly InstanceMetricSampleRepository $instanceMetricSampleRepository,
        private readonly MetricsLiveBuffer $liveBuffer,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%app.status_stale_grace_seconds%')]
        private readonly int $heartbeatGraceSeconds,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Live metrics endpoint — reads from Redis buffer, never from DB.
     * Poll this from the dashboard frontend to get real-time data without
     * adding read pressure to the metrics_samples table.
     */
    #[Route(path: '/live', name: 'admin_metrics_live', methods: ['GET'])]
    public function live(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['error' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        $agentId = trim($request->query->getString('agent', ''));
        if ($agentId === '') {
            return new JsonResponse(['error' => 'agent parameter required.'], Response::HTTP_BAD_REQUEST);
        }

        $agent = $this->agentRepository->find($agentId);

        if ($agent === null) {
            return new JsonResponse(['error' => 'Agent not found.'], Response::HTTP_NOT_FOUND);
        }

        $samples = $this->liveBuffer->fetchSamples($agent);
        $heartbeat = $agent->getLastHeartbeatStats() ?? [];
        $lastSeenAt = $agent->getLastHeartbeatAt();

        return new JsonResponse([
            'agent_id' => $agent->getId(),
            'agent_name' => $agent->getName(),
            'last_seen_at' => $lastSeenAt?->format(\DateTimeInterface::ATOM),
            'online' => $lastSeenAt !== null && (time() - $lastSeenAt->getTimestamp()) <= $this->heartbeatGraceSeconds,
            'current' => is_array($heartbeat['metrics'] ?? null) ? $heartbeat['metrics'] : null,
            'samples' => $samples,
        ]);
    }

    #[Route(path: '', name: 'admin_metrics', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $tab = $request->query->getString('tab', 'host');
        if (!in_array($tab, ['host', 'instances'], true)) {
            $tab = 'host';
        }

        $window = $request->query->getString('window', '60m');
        $since = $this->resolveSince($window);
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = max(1, min(200, (int) $request->query->get('per_page', 100)));
        $nodeFilter = trim($request->query->getString('node', ''));
        $customerFilter = trim($request->query->getString('customer', ''));
        $selectedInstanceId = trim($request->query->getString('instance', ''));
        $selectedInstance = ctype_digit($selectedInstanceId) ? $this->instanceRepository->find((int) $selectedInstanceId) : null;
        $selectedInstanceView = $selectedInstance === null ? null : [
            'id' => $selectedInstance->getId(),
            'booked_cpu_cores' => (float) $selectedInstance->getCpuLimit(),
            'booked_ram_mb' => $selectedInstance->getRamLimit(),
            'customer_email' => $selectedInstance->getCustomer()->getEmail(),
            'node_id' => $selectedInstance->getNode()->getId(),
            'node_name' => $selectedInstance->getNode()->getName(),
        ];

        $loadError = null;

        try {
            $agents = $this->agentRepository->findBy([], ['name' => 'ASC']);
        } catch (\Throwable $exception) {
            $this->logger->error('admin.metrics.agents_failed', [
                'message' => $exception->getMessage(),
            ]);
            $agents = [];
            $loadError = 'agents';
        }

        $selectedAgent = $this->resolveSelectedAgent($agents, $request->query->getString('agent', ''), $selectedInstance);

        $recentSamples = [];
        $sparklineSamples = [];
        $aggregate = [
            'count' => 0,
            'cpu_avg' => null,
            'cpu_max' => null,
            'memory_avg' => null,
            'memory_max' => null,
            'disk_avg' => null,
            'disk_max' => null,
        ];
        $totalSamples = 0;
        $hostTotalPages = 1;

        $instanceMetricAggregate = ['count' => 0, 'cpu_avg' => null, 'cpu_max' => null, 'mem_avg' => null, 'mem_max' => null];
        $instanceMetricRecent = [];
        $instanceMetricTotal = 0;
        $instanceCpuPoints = null;
        $instanceMemPoints = null;

        $instanceBrowseRows = [];
        $instanceBrowseTotal = 0;
        $instanceBrowsePages = 1;

        try {
            if ($selectedAgent !== null) {
                $aggregate = $this->metricSampleRepository->fetchAggregateSnapshotForAgentSince($selectedAgent, $since);
                $totalSamples = $aggregate['count'];
                $recentSamples = $this->metricSampleRepository->findRecentSamplesForAgentSince($selectedAgent, $since, $page, $perPage);
                $sparklineSamples = $this->metricSampleRepository->findSparklineSeriesForAgentSince($selectedAgent, $since, 240);
                $hostTotalPages = max(1, (int) ceil(max(1, $totalSamples) / $perPage));
            }

            $instanceFilterValue = ctype_digit($selectedInstanceId) ? (int) $selectedInstanceId : null;
            $instanceBrowseRows = $this->instanceMetricSampleRepository->findAdminBrowseRows(
                $since,
                $page,
                $perPage,
                $nodeFilter !== '' ? $nodeFilter : null,
                $customerFilter !== '' ? $customerFilter : null,
                $instanceFilterValue,
            );
            $instanceBrowseTotal = $this->instanceMetricSampleRepository->countAdminBrowseRows(
                $since,
                $nodeFilter !== '' ? $nodeFilter : null,
                $customerFilter !== '' ? $customerFilter : null,
                $instanceFilterValue,
            );
            $instanceBrowsePages = max(1, (int) ceil(max(1, $instanceBrowseTotal) / $perPage));

            if ($selectedInstance !== null) {
                $instanceMetricAggregate = $this->instanceMetricSampleRepository->fetchAggregateForInstanceSince($selectedInstance, $since);
                $instanceMetricTotal = $instanceMetricAggregate['count'];
                $instanceMetricRecent = $this->instanceMetricSampleRepository->findRecentForInstanceSince($selectedInstance, $since, $page, $perPage);
                $instanceSparkline = $this->instanceMetricSampleRepository->findSparklineForInstanceSince($selectedInstance, $since, 500);
                $instanceCpuPoints = $this->buildSparklinePoints($this->buildInstanceSeries($instanceSparkline, 'cpu'));
                $instanceMemPoints = $this->buildSparklinePoints($this->buildInstanceSeries($instanceSparkline, 'memory'));
            }
        } catch (\Throwable $exception) {
            $this->logger->error('admin.metrics.series_failed', [
                'agent_id' => $selectedAgent?->getId(),
                'message' => $exception->getMessage(),
            ]);
            $loadError = $loadError ?? 'series';
        }

        $bandwidth = $this->calculateBandwidth($sparklineSamples);
        $cpuSeries = $this->buildSeries($sparklineSamples, 'cpu');
        $memorySeries = $this->buildSeries($sparklineSamples, 'memory');
        $diskSeries = $this->buildSeries($sparklineSamples, 'disk');
        $temperatureSeries = $this->buildTemperatureSeries($sparklineSamples);
        $temperatureAggregate = $this->buildAggregate($temperatureSeries);
        $sampleBandwidth = $this->calculateSampleBandwidth($recentSamples);
        $latest = $recentSamples !== [] ? $recentSamples[0] : null;
        $latestTemperature = $this->extractTemperatureCelsius($latest);

        try {
            $instances = $this->instanceRepository->findBy([], ['createdAt' => 'DESC'], 200);
        } catch (\Throwable $exception) {
            $this->logger->error('admin.metrics.instances_failed', [
                'message' => $exception->getMessage(),
            ]);
            $instances = [];
            $loadError = $loadError ?? 'instances';
        }


        $nodeHealthStatus = 'offline';
        $nodeLastSeenAt = $selectedAgent?->getLastHeartbeatAt();
        if ($selectedAgent !== null && $nodeLastSeenAt !== null) {
            $age = time() - $nodeLastSeenAt->getTimestamp();
            $nodeHealthStatus = $age > $this->heartbeatGraceSeconds ? 'offline' : 'ok';
            $metrics = JsonArray::orEmpty(($selectedAgent->getLastHeartbeatStats() ?? [])['metrics'] ?? null);
            if ($metrics !== [] && $nodeHealthStatus !== 'offline') {
                $peak = max(
                    $this->numericValue(JsonArray::orEmpty($metrics['cpu'] ?? null)['percent'] ?? null) ?? 0.0,
                    $this->numericValue(JsonArray::orEmpty($metrics['memory'] ?? null)['percent'] ?? null) ?? 0.0,
                    $this->numericValue(JsonArray::orEmpty($metrics['disk'] ?? null)['percent'] ?? null) ?? 0.0,
                );
                if ($peak >= 95.0) {
                    $nodeHealthStatus = 'critical';
                } elseif ($peak >= 85.0) {
                    $nodeHealthStatus = 'warning';
                }
            }
        }

        $cpuLine     = $this->buildSparklinePoints($cpuSeries);
        $memLine     = $this->buildSparklinePoints($memorySeries);
        $diskLine    = $this->buildSparklinePoints($diskSeries);
        $netSentLine = $this->buildSparklinePoints($bandwidth['upload']);
        $netRecvLine = $this->buildSparklinePoints($bandwidth['download']);
        $tempLine    = $this->buildSparklinePoints($temperatureSeries);

        $cpuChartLine     = $this->buildSparklinePoints($cpuSeries, 1200, 120);
        $memChartLine     = $this->buildSparklinePoints($memorySeries, 1200, 120);
        $netRecvChartLine = $this->buildSparklinePoints($bandwidth['download'], 1200, 120);
        $netSentChartLine = $this->buildSparklinePoints($bandwidth['upload'], 1200, 120);

        return new Response($this->twig->render('admin/metrics/index.html.twig', [
            'activeNav' => 'metrics',
            'tab' => $tab,
            'agents' => $agents,
            'instances' => $instances,
            'selectedAgent' => $selectedAgent,
            'selectedInstanceId' => $selectedInstanceId,
            'selectedInstance' => $selectedInstanceView,
            'nodeFilter' => $nodeFilter,
            'customerFilter' => $customerFilter,
            'window' => $window,
            'page' => $page,
            'perPage' => $perPage,
            'hostTotalPages' => $hostTotalPages,
            'instanceBrowsePages' => $instanceBrowsePages,
            'totalSamples' => $totalSamples,
            'loadError' => $loadError,
            'recentSamples' => $recentSamples,
            'aggregate' => $aggregate,
            'cpuPoints' => $cpuLine,
            'memoryPoints' => $memLine,
            'diskPoints' => $diskLine,
            'netSentPoints' => $netSentLine,
            'netRecvPoints' => $netRecvLine,
            'latestCpu' => $latest['cpuPercent'] ?? null,
            'latestMemory' => $latest['memoryPercent'] ?? null,
            'latestDisk' => $latest['diskPercent'] ?? null,
            'latestNetSent' => $bandwidth['latestUpload'],
            'latestNetRecv' => $bandwidth['latestDownload'],
            'temperaturePoints' => $tempLine,
            'latestTemperature' => $latestTemperature,
            'temperatureAggregate' => $temperatureAggregate,
            'sampleBandwidth' => $sampleBandwidth,
            'since' => $since,
            'instanceMetricAggregate' => $instanceMetricAggregate,
            'instanceMetricRecent' => $instanceMetricRecent,
            'instanceMetricTotal' => $instanceMetricTotal,
            'instanceCpuPoints' => $instanceCpuPoints,
            'instanceMemPoints' => $instanceMemPoints,
            'instanceBrowseRows' => $instanceBrowseRows,
            'instanceBrowseTotal' => $instanceBrowseTotal,
            'nodeHealthStatus' => $nodeHealthStatus,
            'nodeLastSeenAt' => $nodeLastSeenAt,
            'heartbeatGraceSeconds' => $this->heartbeatGraceSeconds,
            'cpuChartLine' => $cpuChartLine,
            'cpuChartArea' => $cpuChartLine !== null ? $this->buildAreaPoints($cpuChartLine, 1200, 120) : null,
            'memChartLine' => $memChartLine,
            'memChartArea' => $memChartLine !== null ? $this->buildAreaPoints($memChartLine, 1200, 120) : null,
            'netRecvChartLine' => $netRecvChartLine,
            'netRecvChartArea' => $netRecvChartLine !== null ? $this->buildAreaPoints($netRecvChartLine, 1200, 120) : null,
            'netSentChartLine' => $netSentChartLine,
            'netSentChartArea' => $netSentChartLine !== null ? $this->buildAreaPoints($netSentChartLine, 1200, 120) : null,
        ]));
    }

    private function isAdmin(Request $request): bool
    {
        $actor = RequestActor::user($request);
        return $actor instanceof User && $actor->isAdmin();
    }

    /**
     * @param Agent[] $agents
     */
    private function resolveSelectedAgent(array $agents, string $agentId, ?Instance $instance): ?Agent
    {
        if ($instance !== null) {
            $instanceAgentId = $instance->getNode()->getId();
            foreach ($agents as $agent) {
                if ($agent->getId() === $instanceAgentId) {
                    return $agent;
                }
            }
        }

        if ($agentId !== '') {
            foreach ($agents as $agent) {
                if ($agent->getId() === $agentId) {
                    return $agent;
                }
            }
        }

        return $agents[0] ?? null;
    }

    private function resolveSince(string $window): \DateTimeImmutable
    {
        return match ($window) {
            '15m' => new \DateTimeImmutable('-15 minutes'),
            '6h' => new \DateTimeImmutable('-6 hours'),
            '24h' => new \DateTimeImmutable('-24 hours'),
            '7d' => new \DateTimeImmutable('-7 days'),
            default => new \DateTimeImmutable('-60 minutes'),
        };
    }

    /**
     * @param array<int, array{recordedAt: mixed, netBytesSent: mixed, netBytesRecv: mixed, ...}> $samples
     * @return array{upload: list<float>, download: list<float>, latestUpload: ?float, latestDownload: ?float}
     */
    private function calculateBandwidth(array $samples): array
    {
        $upload = [];
        $download = [];

        $previous = null;
        foreach ($samples as $sample) {
            if ($previous === null) {
                $previous = $sample;
                continue;
            }

            $currentAt = $sample['recordedAt'] ?? null;
            $previousAt = $previous['recordedAt'] ?? null;
            if (!$currentAt instanceof \DateTimeImmutable || !$previousAt instanceof \DateTimeImmutable) {
                $previous = $sample;
                continue;
            }

            $seconds = $currentAt->getTimestamp() - $previousAt->getTimestamp();
            if ($seconds <= 0) {
                $previous = $sample;
                continue;
            }

            $sentDelta = $this->toNonNegativeDelta($sample['netBytesSent'] ?? null, $previous['netBytesSent'] ?? null);
            $recvDelta = $this->toNonNegativeDelta($sample['netBytesRecv'] ?? null, $previous['netBytesRecv'] ?? null);

            if ($sentDelta !== null) {
                $upload[] = ($sentDelta * 8.0) / (float) $seconds / 1_000_000.0;
            }
            if ($recvDelta !== null) {
                $download[] = ($recvDelta * 8.0) / (float) $seconds / 1_000_000.0;
            }

            $previous = $sample;
        }

        return [
            'upload' => $upload,
            'download' => $download,
            'latestUpload' => $upload !== [] ? $upload[array_key_last($upload)] : null,
            'latestDownload' => $download !== [] ? $download[array_key_last($download)] : null,
        ];
    }

    /**
     * @param array<int, array{id: mixed, recordedAt: mixed, netBytesSent: mixed, netBytesRecv: mixed, ...}> $samples
     * @return array<int, array{upload: ?float, download: ?float}>
     */
    private function calculateSampleBandwidth(array $samples): array
    {
        $bySampleId = [];

        foreach ($samples as $index => $sample) {
            $sampleId = $sample['id'] ?? null;
            if (!is_numeric($sampleId)) {
                continue;
            }

            $previous = $samples[$index + 1] ?? null;
            if (!is_array($previous)) {
                $bySampleId[(int) $sampleId] = ['upload' => null, 'download' => null];
                continue;
            }

            $currentAt = $sample['recordedAt'] ?? null;
            $previousAt = $previous['recordedAt'] ?? null;
            if (!$currentAt instanceof \DateTimeImmutable || !$previousAt instanceof \DateTimeImmutable) {
                $bySampleId[(int) $sampleId] = ['upload' => null, 'download' => null];
                continue;
            }

            $seconds = $currentAt->getTimestamp() - $previousAt->getTimestamp();
            if ($seconds <= 0) {
                $bySampleId[(int) $sampleId] = ['upload' => null, 'download' => null];
                continue;
            }

            $sentDelta = $this->toNonNegativeDelta($sample['netBytesSent'] ?? null, $previous['netBytesSent'] ?? null);
            $recvDelta = $this->toNonNegativeDelta($sample['netBytesRecv'] ?? null, $previous['netBytesRecv'] ?? null);

            $bySampleId[(int) $sampleId] = [
                'upload' => $sentDelta !== null ? (($sentDelta * 8.0) / (float) $seconds / 1_000_000.0) : null,
                'download' => $recvDelta !== null ? (($recvDelta * 8.0) / (float) $seconds / 1_000_000.0) : null,
            ];
        }

        return $bySampleId;
    }

    private function toNonNegativeDelta(mixed $current, mixed $previous): ?float
    {
        if (!is_numeric($current) || !is_numeric($previous)) {
            return null;
        }

        $delta = (float) $current - (float) $previous;

        return $delta >= 0 ? $delta : null;
    }

    /**
     * @param array<int, array<string, mixed>> $samples
     * @return list<float>
     */
    private function buildSeries(array $samples, string $metric): array
    {
        $values = [];
        foreach ($samples as $sample) {
            $value = match ($metric) {
                'cpu' => $this->numericValue($sample['cpuPercent'] ?? null),
                'memory' => $this->numericValue($sample['memoryPercent'] ?? null),
                'disk' => $this->numericValue($sample['diskPercent'] ?? null),
                'net_sent' => $this->numericValue($sample['netBytesSent'] ?? null),
                'net_recv' => $this->numericValue($sample['netBytesRecv'] ?? null),
                default => null,
            };

            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param array<int, array<string, mixed>> $samples
     * @return list<float>
     */
    private function buildTemperatureSeries(array $samples): array
    {
        $values = [];

        foreach ($samples as $sample) {
            $temperature = $this->extractTemperatureCelsius($sample);
            if ($temperature !== null) {
                $values[] = $temperature;
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed>|null $sample
     */
    private function extractTemperatureCelsius(?array $sample): ?float
    {
        if (!is_array($sample)) {
            return null;
        }

        $payload = JsonArray::orEmpty($sample['payload'] ?? null);

        return $this->numericValue(JsonArray::orEmpty($payload['temperature'] ?? null)['celsius'] ?? null)
            ?? $this->numericValue(JsonArray::orEmpty($payload['temp'] ?? null)['celsius'] ?? null)
            ?? $this->numericValue(JsonArray::orEmpty($payload['thermal'] ?? null)['celsius'] ?? null)
            ?? $this->numericValue($payload['temperature'] ?? null)
            ?? $this->numericValue($payload['temp'] ?? null);
    }

    private function numericValue(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param list<float> $values
     * @return array{avg: ?float, max: ?float}
     */
    private function buildAggregate(array $values): array
    {
        if ($values === []) {
            return ['avg' => null, 'max' => null];
        }

        return [
            'avg' => array_sum($values) / (float) count($values),
            'max' => max($values),
        ];
    }


    /**
     * @param array<int, array<string, mixed>> $samples
     * @return list<float>
     */
    private function buildInstanceSeries(array $samples, string $metric): array
    {
        $values = [];
        foreach ($samples as $sample) {
            $value = match ($metric) {
                'cpu' => $this->numericValue($sample['cpuPercent'] ?? null),
                'memory' => isset($sample['memUsedBytes']) && is_numeric($sample['memUsedBytes'])
                    ? ((float) $sample['memUsedBytes'] / 1024.0 / 1024.0)
                    : null,
                default => null,
            };

            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function buildAreaPoints(string $linePoints, int $width = 1200, int $height = 120): string
    {
        return sprintf('%s %d,%d 0,%d', $linePoints, $width, $height, $height);
    }

    /** @param list<float> $values */
    private function buildSparklinePoints(array $values, int $width = 240, int $height = 60): ?string
    {
        if (count($values) < 2) {
            return null;
        }

        $min = min($values);
        $max = max($values);
        $range = $max - $min;
        $range = $range > 0.0 ? $range : 1.0;

        $points = [];
        $count = count($values);
        foreach ($values as $index => $value) {
            $x = (int) round((float) $index * ((float) $width / (float) max($count - 1, 1)));
            $normalized = ($value - $min) / $range;
            $y = (int) round((float) $height - ($normalized * (float) $height));
            $points[] = sprintf('%d,%d', $x, $y);
        }

        return implode(' ', $points);
    }
}
