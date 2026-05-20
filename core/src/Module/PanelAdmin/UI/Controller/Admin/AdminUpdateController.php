<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Application\AgentReleaseChecker;
use App\Module\Core\Application\AgentUpdateQueueService;
use App\Module\Core\Application\CoreReleaseChecker;
use App\Module\Core\Application\UpdateJobService;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Setup\Application\WebinterfaceUpdateService;
use App\Module\Setup\Application\WebinterfaceUpdateSettingsService;
use App\Repository\AgentRepository;
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
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[Route(path: '/admin/updates')]
final class AdminUpdateController
{
    public function __construct(
        private readonly WebinterfaceUpdateService $updateService,
        private readonly WebinterfaceUpdateSettingsService $updateSettingsService,
        private readonly AgentRepository $agentRepository,
        private readonly AgentReleaseChecker $releaseChecker,
        private readonly AgentUpdateQueueService $agentUpdateQueueService,
        private readonly CoreReleaseChecker $coreReleaseChecker,
        private readonly UpdateJobService $updateJobService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        #[Autowire(service: 'limiter.admin_update_jobs')]
        private readonly RateLimiterFactory $updateLimiter,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
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
        $forceRefresh = $request->query->getBoolean('force') || $request->query->getBoolean('refresh');
        $latestVersion = $this->releaseChecker->getLatestVersionForChannel($agentChannel, $forceRefresh);

        return new Response($this->twig->render('admin/updates/index.html.twig', [
            'activeNav' => 'updates',
            'coreUpdate' => $this->buildCoreUpdateSummary($forceRefresh),
            'agentUpdate' => $this->buildAgentUpdateSummary($agents, $latestVersion, $request->getLocale()),
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

        if ($type === 'update' || $type === 'both') {
            $channel = $this->updateSettingsService->getCoreChannel();
            $releasePackage = $this->coreReleaseChecker->getReleasePackageForChannel($channel);
            if ($releasePackage === null) {
                $summary = $this->buildCoreUpdateSummary();
                $summary['error'] = $this->translateUpdateMessage('admin_updates_core_no_release_asset', $request->getLocale());

                return $this->renderUpdateCard($summary);
            }
            $payload = array_merge($payload, [
                'version' => $releasePackage['version'],
                'channel' => $releasePackage['channel'],
                'download_url' => $releasePackage['download_url'],
                'checksums_url' => $releasePackage['checksums_url'],
                'signature_url' => $releasePackage['signature_url'] ?? null,
                'asset_name' => $releasePackage['asset_name'],
            ]);
        }

        $job = $this->updateJobService->createJob($type, $createdBy, $payload);
        $triggered = $this->updateJobService->triggerRunner($job['id']);

        $summary = $this->buildCoreUpdateSummary();
        if ($triggered) {
            $summary['notice'] = $this->translateUpdateMessage('admin_updates_job_started', $request->getLocale());
        } else {
            $this->updateJobService->markJobFailedToStart($job['id'], 'Update-Runner konnte nicht gestartet werden.');
            $summary['error'] = $this->translateUpdateMessage('admin_updates_runner_not_found', $request->getLocale());
        }

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

    #[Route(path: '/webinterface/check', name: 'admin_updates_webinterface_check', methods: ['POST'])]
    public function refreshCoreCheck(Request $request): Response
    {
        if (!$this->isSuperAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return $this->renderUpdateCard($this->buildCoreUpdateSummary(true));
    }

    #[Route(path: '/agents/check', name: 'admin_updates_agents_check', methods: ['POST'])]
    public function refreshAgentCheck(Request $request): Response
    {
        if (!$this->isSuperAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $agents = $this->agentRepository->findBy([], ['updatedAt' => 'DESC']);
        $agentChannel = $this->updateSettingsService->getAgentChannel();
        $latestVersion = $this->releaseChecker->getLatestVersionForChannel($agentChannel, true);

        return $this->renderAgentUpdateCard($this->buildAgentUpdateSummary($agents, $latestVersion, $request->getLocale()));
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
        $summary = $this->buildAgentUpdateSummary($agents, $latestVersion, $request->getLocale());

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
        $summary = $this->buildAgentUpdateSummary($agents, $latestVersion, $request->getLocale());

        if ($this->agentUpdateQueueService->updatesRequirePanelProxy($agents, $latestVersion, $agentChannel)) {
            $summary['error'] = $this->translateUpdateMessage('admin_updates_agent_private_proxy_blocked', $request->getLocale());

            return $this->renderAgentUpdateCard($summary);
        }

        $analysis = $this->agentUpdateQueueService->analyzeManualUpdateCandidates($agents, $latestVersion, $agentChannel);
        $queued = $this->agentUpdateQueueService->queueAgentUpdates($analysis['eligible'], $latestVersion, [], $agentChannel);

        if ($queued > 0) {
            $summary['notice'] = $this->translateUpdateMessage('admin_updates_agents_notice_count', $request->getLocale(), ['%count%' => (string) $queued]);
            if ($analysis['blocked'] !== []) {
                $summary['error'] = $this->translateUpdateMessage('admin_updates_agents_partially_blocked', $request->getLocale(), ['%count%' => (string) count($analysis['blocked'])]);
            }
        } else {
            if ($analysis['blocked'] !== []) {
                $summary['error'] = $this->translateUpdateMessage('admin_updates_agents_blocked_only', $request->getLocale(), ['%count%' => (string) count($analysis['blocked'])]);
            } else {
                $summary['error'] = $this->translateUpdateMessage('admin_updates_agents_nothing_queued', $request->getLocale());
            }
        }

        $summary['blockedJobs'] = $analysis['blocked'];

        return $this->renderAgentUpdateCard($summary);
    }

    private function isSuperAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        return $actor instanceof User && $actor->getType() === UserType::Superadmin;
    }

    private function buildCronCommand(): string
    {
        $snapshotPath = $this->projectDir . '/srv/setup/cron/easywi-automation.cron';
        if (is_file($snapshotPath)) {
            $content = file_get_contents($snapshotPath);
            if ($content !== false) {
                foreach (explode("\n", $content) as $line) {
                    if (str_contains($line, 'app:update:auto')) {
                        return trim($line);
                    }
                }
            }
        }

        $escaped = str_replace("'", "'\"'\"'", $this->projectDir);

        return sprintf(
            "*/5 * * * * cd '%s' && php bin/console app:update:auto --no-interaction >> var/log/cron-update-auto.log 2>&1",
            $escaped,
        );
    }

    private function buildCoreUpdateSummary(bool $force = false): array
    {
        $status = $this->updateService->checkForUpdate($force);
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
            'packageUrl' => $status->assetUrl,
            'notes' => $status->notes,
            'notesList' => $this->normalizeNotesList($status->notes),
            'manifestError' => $status->error,
            'cacheStatus' => $status->cacheStatus,
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
            'cronCommand' => $this->buildCronCommand(),
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
    private function buildAgentUpdateSummary(array $agents, ?string $latestVersion, string $locale): array
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

        $cacheStatus = $this->releaseChecker->getCacheStatus($agentChannel);

        return [
            'total' => count($agents),
            'updates' => $updates,
            'latestVersion' => $latestVersion,
            'channel' => $agentChannel,
            'error' => $latestVersion === null ? $this->agentReleaseErrorMessage($cacheStatus, $locale) : null,
            'cacheStatus' => $cacheStatus,
            'channels' => AgentReleaseChecker::channels(),
            'notice' => null,
            'blockedJobs' => [],
            'csrf' => [
                'agents_channel' => $this->csrfTokenManager->getToken('admin_update_agents_channel')->getValue(),
            ],
        ];
    }

    /** @param array<string, mixed> $cacheStatus */
    private function agentReleaseErrorMessage(array $cacheStatus, string $locale): string
    {
        if (($cacheStatus['last_error_type'] ?? null) === 'RATE_LIMIT') {
            $reset = $cacheStatus['rate_limit_reset'] ?? null;
            $resetAt = is_int($reset) || (is_string($reset) && ctype_digit($reset)) ? date('Y-m-d H:i:s', (int) $reset) : '—';

            return $this->translateUpdateMessage('admin_updates_agent_rate_limit', $locale, ['%time%' => $resetAt]);
        }
        if (($cacheStatus['last_error_type'] ?? null) === 'ACCESS_DENIED') {
            return $this->translateUpdateMessage('admin_updates_agent_access_denied', $locale);
        }
        if (($cacheStatus['last_error_type'] ?? null) === 'NOT_FOUND') {
            return $this->translateUpdateMessage('admin_updates_agent_not_found', $locale);
        }

        return $this->translateUpdateMessage('admin_updates_agent_no_release', $locale);
    }

    /** @param array<string, string> $parameters */
    private function translateUpdateMessage(string $key, string $locale, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'portal', $locale);
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
