<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\InstanceRepository;
use App\Repository\MetricSampleRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/metrics')]
final class AdminMetricsController
{
    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly InstanceRepository $instanceRepository,
        private readonly MetricSampleRepository $metricSampleRepository,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '', name: 'admin_metrics', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $range = (string) $request->query->get('range', '24h');
        $since = match ($range) {
            '7d' => new \DateTimeImmutable('-7 days'),
            default => new \DateTimeImmutable('-24 hours'),
        };

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

        $selectedAgent = $this->resolveSelectedAgent($agents, (string) $request->query->get('agent', ''));

        try {
            $samples = $selectedAgent === null
                ? []
                : $this->metricSampleRepository->findSeriesForAgentSince($selectedAgent, $since);
        } catch (\Throwable $exception) {
            $this->logger->error('admin.metrics.series_failed', [
                'agent_id' => $selectedAgent?->getId(),
                'message' => $exception->getMessage(),
            ]);
            $samples = [];
            $loadError = $loadError ?? 'series';
        }

        $bandwidth = $this->calculateBandwidth($samples);

        $cpuSeries = $this->buildSeries($samples, 'cpu');
        $memorySeries = $this->buildSeries($samples, 'memory');
        $diskSeries = $this->buildSeries($samples, 'disk');
        $netSentSeries = $bandwidth['upload'];
        $netRecvSeries = $bandwidth['download'];
        $processSeries = $this->buildSeries($samples, 'process_count');
        $temperatureSeries = $this->buildSeries($samples, 'temperature');

        $latest = $samples !== [] ? $samples[array_key_last($samples)] : null;
        $latestProcessCount = $this->extractPayloadValue($latest, 'process_count');
        $latestTemperature = $this->extractTemperature($latest);

        try {
            $instances = $this->instanceRepository->findBy([], ['createdAt' => 'DESC'], 10);
        } catch (\Throwable $exception) {
            $this->logger->error('admin.metrics.instances_failed', [
                'message' => $exception->getMessage(),
            ]);
            $instances = [];
            $loadError = $loadError ?? 'instances';
        }

        return new Response($this->twig->render('admin/metrics/index.html.twig', [
            'activeNav' => 'metrics',
            'agents' => $agents,
            'instances' => $instances,
            'selectedAgent' => $selectedAgent,
            'range' => $range,
            'loadError' => $loadError,
            'cpuPoints' => $this->buildSparklinePoints($cpuSeries),
            'memoryPoints' => $this->buildSparklinePoints($memorySeries),
            'diskPoints' => $this->buildSparklinePoints($diskSeries),
            'netSentPoints' => $this->buildSparklinePoints($netSentSeries),
            'netRecvPoints' => $this->buildSparklinePoints($netRecvSeries),
            'processPoints' => $this->buildSparklinePoints($processSeries),
            'temperaturePoints' => $this->buildSparklinePoints($temperatureSeries),
            'latestCpu' => $latest['cpuPercent'] ?? null,
            'latestMemory' => $latest['memoryPercent'] ?? null,
            'latestDisk' => $latest['diskPercent'] ?? null,
            'latestNetSent' => $bandwidth['latestUpload'],
            'latestNetRecv' => $bandwidth['latestDownload'],
            'latestProcessCount' => $latestProcessCount,
            'latestTemperature' => $latestTemperature,
        ]));
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        return $actor instanceof User && $actor->isAdmin();
    }

    /**
     * @param Agent[] $agents
     */
    private function resolveSelectedAgent(array $agents, string $agentId): ?Agent
    {
        if ($agentId !== '') {
            foreach ($agents as $agent) {
                if ($agent->getId() === $agentId) {
                    return $agent;
                }
            }
        }

        return $agents[0] ?? null;
    }


    /**
     * @param array<int, array{recordedAt: mixed, netBytesSent: mixed, netBytesRecv: mixed}> $samples
     * @return array{upload: float[], download: float[], latestUpload: ?float, latestDownload: ?float}
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
                $upload[] = $sentDelta / $seconds;
            }
            if ($recvDelta !== null) {
                $download[] = $recvDelta / $seconds;
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

    private function toNonNegativeDelta(mixed $current, mixed $previous): ?float
    {
        if (!is_numeric($current) || !is_numeric($previous)) {
            return null;
        }

        $delta = (float) $current - (float) $previous;

        return $delta >= 0 ? $delta : null;
    }

    private function buildSeries(array $samples, string $metric): array
    {
        $values = [];
        foreach ($samples as $sample) {
            $value = match ($metric) {
                'cpu' => $sample['cpuPercent'] ?? null,
                'memory' => $sample['memoryPercent'] ?? null,
                'disk' => $sample['diskPercent'] ?? null,
                'net_sent' => $sample['netBytesSent'] ?? null,
                'net_recv' => $sample['netBytesRecv'] ?? null,
                'process_count' => $this->extractPayloadValue($sample, 'process_count'),
                'temperature' => $this->extractTemperature($sample),
                default => null,
            };

            if ($value !== null) {
                $values[] = (float) $value;
            }
        }

        return $values;
    }

    private function extractPayloadValue(?array $sample, string $key): ?float
    {
        if (!is_array($sample)) {
            return null;
        }
        $payload = $sample['payload'] ?? null;
        if (!is_array($payload)) {
            return null;
        }
        $value = $payload[$key] ?? null;
        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function extractTemperature(?array $sample): ?float
    {
        if (!is_array($sample)) {
            return null;
        }
        $payload = $sample['payload'] ?? null;
        if (!is_array($payload)) {
            return null;
        }
        $temperature = $payload['temperature'] ?? null;
        if (!is_array($temperature)) {
            return null;
        }
        $value = $temperature['celsius'] ?? null;
        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function buildSparklinePoints(array $values, int $width = 240, int $height = 60): ?string
    {
        if (count($values) < 2) {
            return null;
        }

        $min = min($values);
        $max = max($values);
        $range = $max - $min;
        $range = $range > 0 ? $range : 1;

        $points = [];
        $count = count($values);
        foreach ($values as $index => $value) {
            $x = (int) round($index * ($width / max($count - 1, 1)));
            $normalized = ($value - $min) / $range;
            $y = (int) round($height - ($normalized * $height));
            $points[] = sprintf('%d,%d', $x, $y);
        }

        return implode(' ', $points);
    }
}
