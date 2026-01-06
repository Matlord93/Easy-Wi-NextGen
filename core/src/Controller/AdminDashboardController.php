<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\JobStatus;
use App\Enum\UserType;
use App\Repository\AgentRepository;
use App\Repository\JobRepository;
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
        $agents = $this->agentRepository->findBy([], ['updatedAt' => 'DESC'], 6);
        $agentSummary = $this->buildAgentSummary($agents);
        $jobSummary = $this->buildJobSummary($jobs);

        return new Response($this->twig->render('admin/dashboard/index.html.twig', [
            'activeNav' => 'dashboard',
            'jobSummary' => $jobSummary,
            'agentSummary' => $agentSummary,
            'jobs' => $this->normalizeJobs($jobs),
            'nodes' => $this->normalizeAgents($agents),
        ]));
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        return $actor instanceof User && $actor->getType() === UserType::Admin;
    }

    private function buildJobSummary(array $jobs): array
    {
        $summary = [
            'total' => count($jobs),
            'running' => 0,
            'queued' => 0,
            'failed' => 0,
        ];

        foreach ($jobs as $job) {
            switch ($job->getStatus()) {
                case JobStatus::Running:
                    $summary['running']++;
                    break;
                case JobStatus::Queued:
                    $summary['queued']++;
                    break;
                case JobStatus::Failed:
                    $summary['failed']++;
                    break;
                default:
                    break;
            }
        }

        return $summary;
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

            return [
                'id' => $agent->getId(),
                'name' => $agent->getName() ?? 'Unnamed agent',
                'status' => $status,
                'lastHeartbeatAt' => $agent->getLastHeartbeatAt(),
                'lastHeartbeatIp' => $agent->getLastHeartbeatIp(),
                'lastHeartbeatVersion' => $agent->getLastHeartbeatVersion(),
                'cpu' => $stats['cpu'] ?? null,
                'memory' => $stats['memory'] ?? null,
                'queue' => $stats['queue'] ?? null,
            ];
        }, $agents);
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
