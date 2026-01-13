<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Agent;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\AgentRepository;
use App\Repository\InstanceRepository;
use App\Repository\MetricSampleRepository;
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

        $agents = $this->agentRepository->findBy([], ['name' => 'ASC']);
        $selectedAgent = $this->resolveSelectedAgent($agents, (string) $request->query->get('agent', ''));

        $samples = $selectedAgent === null
            ? []
            : $this->metricSampleRepository->findForAgentSince($selectedAgent, $since);

        $cpuSeries = $this->buildSeries($samples, 'cpu');
        $memorySeries = $this->buildSeries($samples, 'memory');
        $diskSeries = $this->buildSeries($samples, 'disk');
        $netSentSeries = $this->buildSeries($samples, 'net_sent');
        $netRecvSeries = $this->buildSeries($samples, 'net_recv');

        $latest = $samples !== [] ? $samples[array_key_last($samples)] : null;

        return new Response($this->twig->render('admin/metrics/index.html.twig', [
            'activeNav' => 'metrics',
            'agents' => $agents,
            'instances' => $this->instanceRepository->findBy([], ['createdAt' => 'DESC'], 10),
            'selectedAgent' => $selectedAgent,
            'range' => $range,
            'cpuPoints' => $this->buildSparklinePoints($cpuSeries),
            'memoryPoints' => $this->buildSparklinePoints($memorySeries),
            'diskPoints' => $this->buildSparklinePoints($diskSeries),
            'netSentPoints' => $this->buildSparklinePoints($netSentSeries),
            'netRecvPoints' => $this->buildSparklinePoints($netRecvSeries),
            'latestCpu' => $latest?->getCpuPercent(),
            'latestMemory' => $latest?->getMemoryPercent(),
            'latestDisk' => $latest?->getDiskPercent(),
            'latestNetSent' => $latest?->getNetBytesSent(),
            'latestNetRecv' => $latest?->getNetBytesRecv(),
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

    private function buildSeries(array $samples, string $metric): array
    {
        $values = [];
        foreach ($samples as $sample) {
            $value = match ($metric) {
                'cpu' => $sample->getCpuPercent(),
                'memory' => $sample->getMemoryPercent(),
                'disk' => $sample->getDiskPercent(),
                'net_sent' => $sample->getNetBytesSent(),
                'net_recv' => $sample->getNetBytesRecv(),
                default => null,
            };

            if ($value !== null) {
                $values[] = (float) $value;
            }
        }

        return $values;
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
