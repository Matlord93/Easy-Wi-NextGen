<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Application\AgentReleaseChecker;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\CoreReleaseChecker;
use App\Module\Core\Application\UpdateJobService;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\JobStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Setup\Application\WebinterfaceUpdateService;
use App\Module\Setup\Application\WebinterfaceUpdateSettingsService;
use App\Repository\AgentRepository;
use App\Repository\JobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
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
        private readonly UpdateJobService $updateJobService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        #[Autowire(service: 'limiter.admin_update_jobs')]
        private readonly RateLimiterFactory $updateLimiter,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_updates', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isSuperAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $agents = $this->agentRepository->findBy([], ['updatedAt' => 'DESC']);
        $agentChannel = $this->updateSettingsService->getAgentChannel();
        $latestVersion = $this->releaseChecker->getLatestVersionForChannel($agentChannel);

        return new Response($this->twig->render('admin/updates/index.html.twig', [
            'activeNav' => 'updates',
            'coreUpdate' => $this->buildCoreUpdateSummary(),
            'agentUpdate' => $this->buildAgentUpdateSummary($agents, $latestVersion),
        ]));
    }

    #[Route(path: '/job', name: 'admin_updates_job', methods: ['POST'])]
    public function createJob(Request $request): Response
    {
        if (!$this->isSuperAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->consumeLimiter($request)) {
            throw new TooManyRequestsHttpException();
        }

        $type = (string) $request->request->get('type');
        $allowed = ['update', 'migrate', 'both', 'rollback'];
        if (!in_array($type, $allowed, true)) {
            return new Response('Invalid type.', Response::HTTP_BAD_REQUEST);
        }

        $token = new CsrfToken('admin_update_' . $type, (string) $request->request->get('csrf_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new UnauthorizedHttpException('csrf', 'Invalid CSRF token.');
        }

        $actor = $request->attributes->get('current_user');
        $createdBy = $actor instanceof User ? $actor->getEmail() : 'system';
        $payload = [];
        if ($type === 'rollback') {
            $payload['backup_path'] = (string) $request->request->get('backup_path');
        }

        $job = $this->updateJobService->createJob($type, $createdBy, $payload);
        $this->updateJobService->triggerRunner($job['id']);

        $summary = $this->buildCoreUpdateSummary();
        $summary['notice'] = 'Job wurde gestartet.';

        return $this->renderUpdateCard($summary);
    }

    #[Route(path: '/job/{id}', name: 'admin_updates_job_status', methods: ['GET'])]
    public function jobStatus(Request $request, string $id): Response
    {
        if (!$this->isSuperAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $job = $this->updateJobService->getJob($id);
        if ($job === null) {
            return new Response('Not Found.', Response::HTTP_NOT_FOUND);
        }

        return new Response(json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}', HttpResponse::HTTP_OK, [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    #[Route(path: '/job/{id}/log', name: 'admin_updates_job_log', methods: ['GET'])]
    public function jobLog(Request $request, string $id): Response
    {
        if (!$this->isSuperAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $job = $this->updateJobService->getJob($id);
        if ($job === null) {
            return new Response('Not Found.', Response::HTTP_NOT_FOUND);
        }

        $lines = $this->updateJobService->tailLog($job['logPath'] ?? null);

        return new Response(json_encode([
            'job_id' => $id,
            'lines' => $lines,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}', HttpResponse::HTTP_OK, [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    #[Route(path: '/webinterface/auto', name: 'admin_updates_webinterface_auto', methods: ['POST'])]
    public function toggleAutoUpdates(Request $request): Response
    {
        if (!$this->isSuperAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $enabled = $request->request->getBoolean('enabled');
        $this->updateSettingsService->setAutoEnabled($enabled);

        return $this->renderUpdateCard($this->buildCoreUpdateSummary());
    }

    #[Route(path: '/webinterface/auto-migrate', name: 'admin_updates_webinterface_auto_migrate', methods: ['POST'])]
    public function toggleAutoMigrate(Request $request): Response
    {
        if (!$this->isSuperAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $enabled = $request->request->getBoolean('enabled');
        $this->updateSettingsService->setAutoMigrate($enabled);

        return $this->renderUpdateCard($this->buildCoreUpdateSummary());
    }

    #[Route(path: '/core/channel', name: 'admin_updates_core_channel', methods: ['POST'])]
    public function setCoreChannel(Request $request): Response
    {
        if (!$this->isSuperAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $token = new CsrfToken('admin_update_core_channel', (string) $request->request->get('csrf_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new UnauthorizedHttpException('csrf', 'Invalid CSRF token.');
        }

        $channel = (string) $request->request->get('channel', CoreReleaseChecker::CHANNEL_STABLE);
        $this->updateSettingsService->setCoreChannel($channel);

        return $this->renderUpdateCard($this->buildCoreUpdateSummary());
    }

    #[Route(path: '/agents/channel', name: 'admin_updates_agents_channel', methods: ['POST'])]
    public function setAgentChannel(Request $request): Response
    {
        if (!$this->isSuperAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $token = new CsrfToken('admin_update_agents_channel', (string) $request->request->get('csrf_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new UnauthorizedHttpException('csrf', 'Invalid CSRF token.');
        }

        $channel = (string) $request->request->get('channel', AgentReleaseChecker::CHANNEL_STABLE);
        $this->updateSettingsService->setAgentChannel($channel);

        $agents = $this->agentRepository->findBy([], ['updatedAt' => 'DESC']);
        $latestVersion = $this->releaseChecker->getLatestVersionForChannel($channel);
        $summary = $this->buildAgentUpdateSummary($agents, $latestVersion);

        return $this->renderAgentUpdateCard($summary);
    }

    #[Route(path: '/agents/update', name: 'admin_updates_agents', methods: ['POST'])]
    public function updateAgents(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Superadmin) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $agentChannel = $this->updateSettingsService->getAgentChannel();
        $agents = $this->agentRepository->findBy([], ['updatedAt' => 'DESC']);
        $latestVersion = $this->releaseChecker->getLatestVersionForChannel($agentChannel);
        $updateJobs = $this->buildUpdateJobIndex($agents);

        $this->queueAgentUpdates($agents, $latestVersion, $updateJobs, $actor, $agentChannel);

        $summary = $this->buildAgentUpdateSummary($agents, $latestVersion);
        $summary['notice'] = true;

        return $this->renderAgentUpdateCard($summary);
    }

    private function isSuperAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        return $actor instanceof User && $actor->getType() === UserType::Superadmin;
    }

    private function buildCoreUpdateSummary(): array
    {
        $status = $this->updateService->checkForUpdate();
        $settings = $this->updateSettingsService->getSettings();
        $versionInfo = $this->updateJobService->getVersionInfo();
        $migrationStatus = $this->updateJobService->getMigrationStatus();
        $latestJob = $this->updateJobService->getLatestJob();
        $logLines = $this->updateJobService->tailLog($latestJob['logPath'] ?? null);
        $backups = $this->updateJobService->listBackups();

        return [
            'currentVersion' => $status->installedVersion,
            'currentCommit' => $versionInfo['commit'],
            'currentBuild' => $versionInfo['build'],
            'latestVersion' => $status->latestVersion,
            'updateAvailable' => $status->updateAvailable,
            'notes' => $status->notes,
            'notesList' => $this->normalizeNotesList($status->notes),
            'manifestError' => $status->error,
            'logPath' => null,
            'latestJob' => $latestJob,
            'logLines' => $logLines,
            'migrationStatus' => $migrationStatus,
            'backups' => $backups,
            'notice' => null,
            'error' => null,
            'autoEnabled' => $settings['autoEnabled'],
            'autoMigrate' => $settings['autoMigrate'],
            'channel' => $settings['coreChannel'],
            'channels' => CoreReleaseChecker::channels(),
            'csrf' => [
                'update' => $this->csrfTokenManager->getToken('admin_update_update')->getValue(),
                'migrate' => $this->csrfTokenManager->getToken('admin_update_migrate')->getValue(),
                'both' => $this->csrfTokenManager->getToken('admin_update_both')->getValue(),
                'rollback' => $this->csrfTokenManager->getToken('admin_update_rollback')->getValue(),
                'core_channel' => $this->csrfTokenManager->getToken('admin_update_core_channel')->getValue(),
            ],
        ];
    }

    /**
     * @param Agent[] $agents
     */
    private function buildAgentUpdateSummary(array $agents, ?string $latestVersion): array
    {
        $agentChannel = $this->updateSettingsService->getAgentChannel();
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
            'channel' => $agentChannel,
            'channels' => AgentReleaseChecker::channels(),
            'notice' => null,
            'csrf' => [
                'agents_channel' => $this->csrfTokenManager->getToken('admin_update_agents_channel')->getValue(),
            ],
        ];
    }

    private function renderUpdateCard(array $summary): Response
    {
        return new Response($this->twig->render('admin/dashboard/_web_update_card.html.twig', [
            'coreUpdate' => $summary,
        ]), HttpResponse::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    private function renderAgentUpdateCard(array $summary): Response
    {
        return new Response($this->twig->render('admin/updates/_agent_update_card.html.twig', [
            'agentUpdate' => $summary,
        ]), HttpResponse::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    private function consumeLimiter(Request $request): bool
    {
        $key = $request->getClientIp() ?? 'unknown';
        $limiter = $this->updateLimiter->create($key);
        return $limiter->consume(1)->isAccepted();
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
    private function queueAgentUpdates(array $agents, ?string $latestVersion, array $existingJobs, User $actor, string $channel): void
    {
        foreach ($agents as $agent) {
            $payload = $this->buildAgentUpdatePayload($agent, $latestVersion, $channel);
            if ($payload === null) {
                continue;
            }

            $existingJob = $existingJobs[$agent->getId()] ?? null;
            if ($existingJob !== null) {
                if ($existingJob->getStatus() === JobStatus::Running) {
                    continue;
                }

                if ($existingJob->getStatus() === JobStatus::Queued) {
                    $existingPayload = $existingJob->getPayload();
                    $existingVersion = is_string($existingPayload['version'] ?? null) ? (string) $existingPayload['version'] : null;
                    $newVersion = is_string($payload['version'] ?? null) ? (string) $payload['version'] : null;

                    if ($existingVersion !== null && $newVersion !== null
                        && $this->releaseChecker->isUpdateAvailable($existingVersion, $newVersion) === true) {
                        $existingJob->transitionTo(JobStatus::Cancelled);
                    } else {
                        continue;
                    }
                }
            }

            $job = new Job($this->resolveAgentUpdateJobType($agent), $payload);
            $this->entityManager->persist($job);

            $this->auditLogger->log($actor, 'node.agent_update_queued', [
                'node_id' => $agent->getId(),
                'job_id' => $job->getId(),
                'version' => $payload['version'],
                'asset_name' => $payload['asset_name'],
                'channel' => $channel,
            ]);
        }

        $this->entityManager->flush();
    }

    private function buildAgentUpdatePayload(Agent $agent, ?string $latestVersion, string $channel): ?array
    {
        $currentVersion = $agent->getLastHeartbeatVersion();

        $stats = $agent->getLastHeartbeatStats() ?? [];
        $os = is_string($stats['os'] ?? null) ? strtolower($stats['os']) : '';
        $arch = is_string($stats['arch'] ?? null) ? strtolower($stats['arch']) : '';

        $assetName = $this->resolveAgentAssetName($os, $arch);
        if ($assetName === null) {
            return null;
        }

        $releaseInfo = $this->releaseChecker->getReleaseAssetUrlsForChannel($assetName, $channel);
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
            'channel' => $channel,
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
