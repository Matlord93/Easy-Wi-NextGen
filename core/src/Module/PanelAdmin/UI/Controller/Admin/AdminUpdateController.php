<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Application\AgentReleaseChecker;
use App\Module\Core\Application\AuditLogger;
use App\Module\Setup\Application\WebinterfaceUpdateService;
use App\Module\Setup\Application\WebinterfaceUpdateSettingsService;
use App\Repository\AgentRepository;
use App\Repository\JobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/updates')]
final class AdminUpdateController
{
    public function __construct(
        private readonly WebinterfaceUpdateService $updateService,
        private readonly WebinterfaceUpdateSettingsService $updateSettingsService,
        private readonly AgentRepository $agentRepository,
        private readonly JobRepository $jobRepository,
        private readonly AgentReleaseChecker $releaseChecker,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_updates', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $agents = $this->agentRepository->findBy([], ['updatedAt' => 'DESC']);
        $latestVersion = $this->releaseChecker->getLatestVersion();

        return new Response($this->twig->render('admin/updates/index.html.twig', [
            'activeNav' => 'updates',
            'coreUpdate' => $this->buildCoreUpdateSummary(),
            'agentUpdate' => $this->buildAgentUpdateSummary($agents, $latestVersion),
        ]));
    }

    #[Route(path: '/webinterface', name: 'admin_updates_webinterface', methods: ['POST'])]
    public function updateWebinterface(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $result = $this->updateService->applyUpdate();
        $summary = $this->buildCoreUpdateSummary();
        if ($result->success) {
            $summary['notice'] = $result->message;
        } else {
            $summary['error'] = $result->error ?? $result->message;
        }
        $summary['logPath'] = $result->logPath;

        return $this->renderUpdateCard($summary);
    }

    #[Route(path: '/webinterface/auto', name: 'admin_updates_webinterface_auto', methods: ['POST'])]
    public function toggleAutoUpdates(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $enabled = $request->request->getBoolean('enabled');
        $this->updateSettingsService->setAutoEnabled($enabled);

        return $this->renderUpdateCard($this->buildCoreUpdateSummary());
    }

    #[Route(path: '/agents/update', name: 'admin_updates_agents', methods: ['POST'])]
    public function updateAgents(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $agents = $this->agentRepository->findBy([], ['updatedAt' => 'DESC']);
        $latestVersion = $this->releaseChecker->getLatestVersion();
        $updateJobs = $this->buildUpdateJobIndex($agents);

        $this->queueAgentUpdates($agents, $latestVersion, $updateJobs, $actor);

        $summary = $this->buildAgentUpdateSummary($agents, $latestVersion);
        $summary['notice'] = true;

        return $this->renderAgentUpdateCard($summary);
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        return $actor instanceof User && $actor->isAdmin();
    }

    private function buildCoreUpdateSummary(): array
    {
        $status = $this->updateService->checkForUpdate();
        $settings = $this->updateSettingsService->getSettings();

        return [
            'currentVersion' => $status->installedVersion,
            'latestVersion' => $status->latestVersion,
            'updateAvailable' => $status->updateAvailable,
            'notes' => $status->notes,
            'notesList' => $this->normalizeNotesList($status->notes),
            'manifestError' => $status->error,
            'logPath' => null,
            'notice' => null,
            'error' => null,
            'autoEnabled' => $settings['autoEnabled'],
        ];
    }

    /**
     * @param Agent[] $agents
     */
    private function buildAgentUpdateSummary(array $agents, ?string $latestVersion): array
    {
        $updates = 0;
        if ($latestVersion !== null && $latestVersion !== '') {
            foreach ($agents as $agent) {
                if ($this->releaseChecker->isUpdateAvailable($agent->getLastHeartbeatVersion(), $latestVersion)) {
                    $updates++;
                }
            }
        }

        return [
            'total' => count($agents),
            'updates' => $updates,
            'latestVersion' => $latestVersion,
            'notice' => null,
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

    private function renderAgentUpdateCard(array $summary): Response
    {
        return new Response($this->twig->render('admin/updates/_agent_update_card.html.twig', [
            'agentUpdate' => $summary,
        ]), HttpResponse::HTTP_OK, [
            ResponseHeaderBag::CONTENT_TYPE => 'text/html; charset=UTF-8',
        ]);
    }

    /**
     * @return array<string, Job>
     */
    private function buildUpdateJobIndex(array $agents): array
    {
        if ($agents === []) {
            return [];
        }

        $agentIds = array_map(static fn (Agent $agent): string => $agent->getId(), $agents);
        $limit = max(50, count($agents) * 4);
        $jobs = array_merge(
            $this->jobRepository->findLatestByType('agent.update', $limit),
            $this->jobRepository->findLatestByType('agent.self_update', $limit),
        );
        usort($jobs, static function (Job $a, Job $b): int {
            return $b->getCreatedAt() <=> $a->getCreatedAt();
        });

        $index = [];
        foreach ($jobs as $job) {
            $payload = $job->getPayload();
            $agentId = is_string($payload['agent_id'] ?? null) ? $payload['agent_id'] : null;
            if ($agentId === null || $agentId === '') {
                continue;
            }

            if (!in_array($agentId, $agentIds, true)) {
                continue;
            }

            if (!array_key_exists($agentId, $index)) {
                $index[$agentId] = $job;
            }
        }

        return $index;
    }

    /**
     * @param Agent[] $agents
     * @param array<string, Job> $existingJobs
     */
    private function queueAgentUpdates(array $agents, ?string $latestVersion, array $existingJobs, User $actor): void
    {
        foreach ($agents as $agent) {
            $payload = $this->buildAgentUpdatePayload($agent, $latestVersion);
            if ($payload === null) {
                continue;
            }

            $existingJob = $existingJobs[$agent->getId()] ?? null;
            if ($existingJob !== null && in_array($existingJob->getStatus()->value, ['queued', 'running'], true)) {
                continue;
            }

            $job = new Job($this->resolveAgentUpdateJobType($agent), $payload);
            $this->entityManager->persist($job);

            $this->auditLogger->log($actor, 'node.agent_update_queued', [
                'node_id' => $agent->getId(),
                'job_id' => $job->getId(),
                'version' => $payload['version'],
                'asset_name' => $payload['asset_name'],
            ]);
        }

        $this->entityManager->flush();
    }

    private function buildAgentUpdatePayload(Agent $agent, ?string $latestVersion): ?array
    {
        $currentVersion = $agent->getLastHeartbeatVersion();

        $stats = $agent->getLastHeartbeatStats() ?? [];
        $os = is_string($stats['os'] ?? null) ? strtolower($stats['os']) : '';
        $arch = is_string($stats['arch'] ?? null) ? strtolower($stats['arch']) : '';

        $assetName = $this->resolveAgentAssetName($os, $arch);
        if ($assetName === null) {
            return null;
        }

        $releaseInfo = $this->releaseChecker->getReleaseAssetUrls($assetName);
        if ($releaseInfo === null) {
            return null;
        }

        $version = $releaseInfo['version'] ?? null;
        if (!is_string($version) || $version === '') {
            return null;
        }

        if ($this->releaseChecker->isUpdateAvailable($currentVersion, $latestVersion ?? $version) !== true) {
            return null;
        }

        return [
            'agent_id' => $agent->getId(),
            'download_url' => $releaseInfo['download_url'],
            'checksums_url' => $releaseInfo['checksums_url'],
            'signature_url' => $releaseInfo['signature_url'] ?? null,
            'asset_name' => $releaseInfo['asset_name'],
            'version' => $version,
        ];
    }

    private function resolveAgentAssetName(string $os, string $arch): ?string
    {
        if ($os === 'linux' && $arch === 'amd64') {
            return 'easywi-agent-linux-amd64';
        }

        if ($os === 'windows' && $arch === 'amd64') {
            return 'easywi-agent-windows-amd64.exe';
        }

        return null;
    }

    private function resolveAgentUpdateJobType(Agent $agent): string
    {
        $stats = $agent->getLastHeartbeatStats() ?? [];
        $os = is_string($stats['os'] ?? null) ? strtolower($stats['os']) : '';

        return $os === 'windows' ? 'agent.self_update' : 'agent.update';
    }

    private function normalizeNotesList(?string $notes): array
    {
        if ($notes === null) {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $notes);
        if ($lines === false) {
            return [];
        }

        $items = [];
        foreach ($lines as $line) {
            $item = trim($line);
            if ($item === '') {
                continue;
            }
            $item = ltrim($item, "-* \t");
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }
}
