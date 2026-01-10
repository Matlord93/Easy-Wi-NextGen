<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\JobStatus;
use App\Enum\TicketStatus;
use App\Enum\UserType;
use App\Repository\AgentRepository;
use App\Repository\InstanceRepository;
use App\Repository\JobRepository;
use App\Repository\TicketRepository;
use App\Repository\UserRepository;
use App\Service\CoreReleaseChecker;
use App\Service\GithubWorkflowDispatcher;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
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
        private readonly TicketRepository $ticketRepository,
        private readonly CoreReleaseChecker $coreReleaseChecker,
        private readonly GithubWorkflowDispatcher $workflowDispatcher,
        #[Autowire('%app.core_version%')]
        private readonly string $coreVersion,
        #[Autowire('%app.core_update_install_dir%')]
        private readonly string $coreUpdateInstallDir,
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
        ];

        return new Response($this->twig->render('admin/dashboard/index.html.twig', [
            'activeNav' => 'dashboard',
            'jobSummary' => $jobSummary,
            'agentSummary' => $agentSummary,
            'overview' => $overview,
            'jobs' => $this->normalizeJobs($jobs),
            'nodes' => $this->normalizeAgents(array_slice($agents, 0, 6)),
            'coreUpdate' => $this->buildCoreUpdateSummary(),
        ]));
    }

    #[Route(path: '/update/webinterface', name: 'admin_update_webinterface', methods: ['POST'])]
    public function updateWebinterface(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $summary = $this->buildCoreUpdateSummary();
        if (!$this->workflowDispatcher->isConfigured()) {
            $summary['error'] = 'GitHub dispatch is not configured.';
            return $this->renderUpdateCard($summary);
        }

        if ($summary['latestVersion'] === null) {
            $summary['error'] = 'Unable to resolve latest version.';
            return $this->renderUpdateCard($summary);
        }

        try {
            $this->workflowDispatcher->dispatch($summary['latestVersion'], [
                'install_dir' => $this->coreUpdateInstallDir,
                'ref' => $summary['latestVersion'],
            ]);
            $summary['notice'] = 'Update dispatched to GitHub.';
        } catch (\RuntimeException $exception) {
            $summary['error'] = $exception->getMessage();
        }

        return $this->renderUpdateCard($summary);
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        return $actor instanceof User && $actor->getType() === UserType::Admin;
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

    private function buildCoreUpdateSummary(): array
    {
        $latestVersion = $this->coreReleaseChecker->getLatestVersion();
        $updateAvailable = $this->coreReleaseChecker->isUpdateAvailable($this->coreVersion, $latestVersion);

        return [
            'currentVersion' => $this->coreVersion !== '' ? $this->coreVersion : null,
            'latestVersion' => $latestVersion,
            'updateAvailable' => $updateAvailable,
            'channel' => $this->coreReleaseChecker->getChannel(),
            'repository' => $this->coreReleaseChecker->getRepository(),
            'workflowConfigured' => $this->workflowDispatcher->isConfigured(),
            'notice' => null,
            'error' => null,
        ];
    }

    private function renderUpdateCard(array $summary): Response
    {
        return new Response($this->twig->render('admin/dashboard/_web_update_card.html.twig', [
            'coreUpdate' => $summary,
        ]), HttpResponse::HTTP_OK, [
            ResponseHeaderBag::CONTENT_TYPE => 'text/html; charset=UTF-8',
        ]);
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
