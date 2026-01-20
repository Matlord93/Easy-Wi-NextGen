<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\AgentRepository;
use App\Repository\DdosStatusRepository;
use App\Repository\JobRepository;
use App\Module\Core\Application\AgentReleaseChecker;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\DiskUsageFormatter;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Application\NodeDiskProtectionService;
use App\Module\Core\Application\SecretsCrypto;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use App\Repository\FirewallStateRepository;
use App\Repository\InstanceRepository;
use App\Repository\MetricSampleRepository;
use App\Repository\Ts3InstanceRepository;
use App\Repository\Ts6InstanceRepository;
use App\Repository\WebspaceRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/nodes')]
final class AdminNodeController
{
    private const ROLE_OPTIONS = ['Core', 'Web', 'Mail', 'DNS', 'Game', 'DB', 'TS3', 'TS6', 'Sinusbot'];
    private const ENCRYPTION_CONFIG_ERROR = 'Encryption key configuration is invalid: %s Set APP_ENCRYPTION_KEY_ID to match a key in APP_ENCRYPTION_KEYS (format: key_id:base64_32_byte_key). Example: APP_ENCRYPTION_KEY_ID=v1 and APP_ENCRYPTION_KEYS=v1:<base64 key>.';

    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly JobRepository $jobRepository,
        private readonly DdosStatusRepository $ddosStatusRepository,
        private readonly Environment $twig,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly AgentReleaseChecker $releaseChecker,
        private readonly EncryptionService $encryptionService,
        private readonly SecretsCrypto $secretsCrypto,
        private readonly NodeDiskProtectionService $nodeDiskProtectionService,
        private readonly DiskUsageFormatter $diskUsageFormatter,
        private readonly InstanceRepository $instanceRepository,
        private readonly WebspaceRepository $webspaceRepository,
        private readonly Ts3InstanceRepository $ts3InstanceRepository,
        private readonly Ts6InstanceRepository $ts6InstanceRepository,
        private readonly MetricSampleRepository $metricSampleRepository,
        private readonly FirewallStateRepository $firewallStateRepository,
    ) {
    }

    #[Route(path: '', name: 'admin_nodes', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $nodes = $this->agentRepository->findBy([], ['updatedAt' => 'DESC']);
        $selectedNodeId = trim((string) $request->query->get('node', ''));
        $latestVersion = $this->releaseChecker->getLatestVersion();
        $summary = $this->buildSummary($nodes, $latestVersion);
        $updateJobs = $this->buildUpdateJobIndex($nodes);
        $serverUpdateJobs = $this->buildServerUpdateJobIndex($nodes);
        $ddosStatuses = $this->buildDdosStatusIndex($nodes);
        $normalizedNodes = $this->normalizeNodes($nodes, $latestVersion, $updateJobs, $serverUpdateJobs, $ddosStatuses);
        $nodeIndex = [];
        foreach ($nodes as $node) {
            $nodeIndex[$node->getId()] = $node;
        }
        $now = new \DateTimeImmutable();
        $diskProtectActive = array_filter($nodes, fn ($node) => $this->nodeDiskProtectionService->isProtectionActive($node, $now));
        $selectedNode = $selectedNodeId !== ''
            ? current(array_filter($normalizedNodes, static fn (array $node): bool => $node['id'] === $selectedNodeId)) ?: ($normalizedNodes[0] ?? null)
            : ($normalizedNodes[0] ?? null);

        if ($selectedNode !== null) {
            $selectedNodeEntity = $nodeIndex[$selectedNode['id']] ?? null;
            if ($selectedNodeEntity !== null) {
                $usage = $this->buildNodeUsage($selectedNodeEntity);
                $selectedNode['delete'] = [
                    'allowed' => !$this->hasNodeUsage($usage),
                    'message' => $this->formatNodeUsageMessage($usage),
                ];
            }
        }

        return new Response($this->twig->render('admin/nodes/index.html.twig', [
            'nodes' => $normalizedNodes,
            'summary' => $summary,
            'roleOptions' => self::ROLE_OPTIONS,
            'selectedNode' => $selectedNode,
            'updateChannel' => $this->releaseChecker->getChannel(),
            'diskProtectCount' => count($diskProtectActive),
            'activeNav' => 'nodes',
        ]));
    }

    #[Route(path: '/register', name: 'admin_nodes_register', methods: ['GET'])]
    public function registerForm(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/nodes/register.html.twig', [
            'activeNav' => 'nodes',
            'form' => [
                'agent_id' => '',
                'name' => '',
                'errors' => [],
            ],
            'config' => null,
        ]));
    }

    #[Route(path: '/register', name: 'admin_nodes_register_create', methods: ['POST'])]
    public function register(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $agentId = trim((string) $request->request->get('agent_id', ''));
        $name = trim((string) $request->request->get('name', ''));
        $errors = [];

        if ($agentId === '') {
            $agentId = sprintf('node-%s', bin2hex(random_bytes(4)));
        }

        if (mb_strlen($agentId) > 64) {
            $errors[] = 'Agent ID must be 64 characters or less.';
        }

        if ($this->agentRepository->find($agentId) !== null) {
            $errors[] = 'Agent ID is already registered.';
        }

        if ($errors !== []) {
            return new Response($this->twig->render('admin/nodes/register.html.twig', [
                'activeNav' => 'nodes',
                'form' => [
                    'agent_id' => $agentId,
                    'name' => $name,
                    'errors' => $errors,
                ],
                'config' => null,
            ]), Response::HTTP_BAD_REQUEST);
        }

        $secret = bin2hex(random_bytes(32));

        try {
            $secretPayload = $this->encryptionService->encrypt($secret);
        } catch (RuntimeException $exception) {
            $errors[] = sprintf(self::ENCRYPTION_CONFIG_ERROR, $exception->getMessage() . '.');

            return new Response($this->twig->render('admin/nodes/register.html.twig', [
                'activeNav' => 'nodes',
                'form' => [
                    'agent_id' => $agentId,
                    'name' => $name,
                    'errors' => $errors,
                ],
                'config' => null,
            ]), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $agent = new \App\Module\Core\Domain\Entity\Agent($agentId, $secretPayload, $name !== '' ? $name : null);
        $this->entityManager->persist($agent);
        $this->auditLogger->log($actor, 'node.registered', [
            'agent_id' => $agentId,
            'name' => $name !== '' ? $name : null,
        ]);
        $this->entityManager->flush();

        $apiUrl = $request->getSchemeAndHttpHost();

        return new Response($this->twig->render('admin/nodes/register.html.twig', [
            'activeNav' => 'nodes',
            'form' => [
                'agent_id' => '',
                'name' => '',
                'errors' => [],
            ],
            'config' => [
                'agent_id' => $agentId,
                'name' => $name !== '' ? $name : null,
                'secret' => $secret,
                'api_url' => $apiUrl,
            ],
        ]));
    }

    #[Route(path: '/table', name: 'admin_nodes_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $nodes = $this->agentRepository->findBy([], ['updatedAt' => 'DESC']);
        $latestVersion = $this->releaseChecker->getLatestVersion();
        $updateJobs = $this->buildUpdateJobIndex($nodes);
        $serverUpdateJobs = $this->buildServerUpdateJobIndex($nodes);
        $ddosStatuses = $this->buildDdosStatusIndex($nodes);

        return new Response($this->twig->render('admin/nodes/_table.html.twig', [
            'nodes' => $this->normalizeNodes($nodes, $latestVersion, $updateJobs, $serverUpdateJobs, $ddosStatuses),
            'roleOptions' => self::ROLE_OPTIONS,
            'updateChannel' => $this->releaseChecker->getChannel(),
        ]));
    }

    #[Route(path: '/{id}/roles', name: 'admin_nodes_roles', methods: ['POST'])]
    public function updateRoles(Request $request, string $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $node = $this->agentRepository->find($id);
        if ($node === null) {
            return new Response('Node not found.', Response::HTTP_NOT_FOUND);
        }

        $submittedRoles = $request->request->all('roles');
        $normalizedRoles = $this->normalizeRoleSelection(is_array($submittedRoles) ? $submittedRoles : []);
        $existingRoles = $this->normalizeRoleSelection($node->getRoles());
        $unknownRoles = $this->extractUnknownRoles($node->getRoles());
        $existingStoredRoles = array_values(array_merge($existingRoles, $unknownRoles));
        $rolesToStore = array_values(array_merge($normalizedRoles, $unknownRoles));
        $notice = null;
        $error = null;

        if ($normalizedRoles === [] && $unknownRoles === []) {
            $error = 'Select at least one role to assign.';
        }

        if ($error === null && $this->rolesChanged($existingStoredRoles, $rolesToStore)) {
            $node->setRoles($rolesToStore);

            foreach ($rolesToStore as $role) {
                $job = new Job('role.ensure_base', [
                    'agent_id' => $node->getId(),
                    'role' => $role,
                ]);
                $this->entityManager->persist($job);
            }

            $this->auditLogger->log($actor, 'node.roles_updated', [
                'node_id' => $node->getId(),
                'previous_roles' => $existingStoredRoles,
                'roles' => $rolesToStore,
            ]);

            $this->entityManager->flush();
            $notice = 'Roles updated. The agent will apply changes on its next job poll.';
        } elseif ($error === null) {
            $notice = 'Roles are already up to date.';
        }

        return $this->renderNodesTable($notice, $error);
    }

    #[Route(path: '/{id}/disk-settings', name: 'admin_nodes_disk_settings', methods: ['POST'])]
    public function updateDiskSettings(Request $request, string $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $node = $this->agentRepository->find($id);
        if ($node === null) {
            return new Response('Node not found.', Response::HTTP_NOT_FOUND);
        }

        $scanInterval = (int) $request->request->get('disk_scan_interval_seconds', $node->getDiskScanIntervalSeconds());
        $warningPercent = (int) $request->request->get('disk_warning_percent', $node->getDiskWarningPercent());
        $hardBlockPercent = (int) $request->request->get('disk_hard_block_percent', $node->getDiskHardBlockPercent());
        $protectionThreshold = (int) $request->request->get('node_disk_protection_threshold_percent', $node->getNodeDiskProtectionThresholdPercent());

        if ($scanInterval < 60) {
            return $this->renderNodesTable(null, 'Scan interval must be at least 60 seconds.');
        }
        if ($warningPercent < 1 || $warningPercent > 99) {
            return $this->renderNodesTable(null, 'Warning threshold must be between 1 and 99 percent.');
        }
        if ($hardBlockPercent < 100) {
            return $this->renderNodesTable(null, 'Hard block threshold must be 100 percent or higher.');
        }
        if ($protectionThreshold < 1 || $protectionThreshold > 20) {
            return $this->renderNodesTable(null, 'Protect mode threshold must be between 1 and 20 percent.');
        }

        $previous = [
            'disk_scan_interval_seconds' => $node->getDiskScanIntervalSeconds(),
            'disk_warning_percent' => $node->getDiskWarningPercent(),
            'disk_hard_block_percent' => $node->getDiskHardBlockPercent(),
            'node_disk_protection_threshold_percent' => $node->getNodeDiskProtectionThresholdPercent(),
        ];

        $node->setDiskScanIntervalSeconds($scanInterval);
        $node->setDiskWarningPercent($warningPercent);
        $node->setDiskHardBlockPercent($hardBlockPercent);
        $node->setNodeDiskProtectionThresholdPercent($protectionThreshold);
        $this->entityManager->persist($node);

        $this->auditLogger->log($actor, 'node.disk.settings_updated', [
            'node_id' => $node->getId(),
            'previous' => $previous,
            'settings' => [
                'disk_scan_interval_seconds' => $node->getDiskScanIntervalSeconds(),
                'disk_warning_percent' => $node->getDiskWarningPercent(),
                'disk_hard_block_percent' => $node->getDiskHardBlockPercent(),
                'node_disk_protection_threshold_percent' => $node->getNodeDiskProtectionThresholdPercent(),
            ],
        ]);

        $this->entityManager->flush();

        return $this->renderNodesTable('Disk settings updated.');
    }

    #[Route(path: '/{id}/agent-settings', name: 'admin_nodes_agent_settings', methods: ['POST'])]
    public function updateAgentSettings(Request $request, string $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $node = $this->agentRepository->find($id);
        if ($node === null) {
            return new Response('Node not found.', Response::HTTP_NOT_FOUND);
        }

        $agentBaseUrl = trim((string) $request->request->get('agent_base_url', ''));
        $agentApiToken = trim((string) $request->request->get('agent_api_token', ''));
        $clearToken = $request->request->getBoolean('clear_agent_token');

        $node->setAgentBaseUrl($agentBaseUrl !== '' ? $agentBaseUrl : null);

        if ($clearToken) {
            $node->clearAgentApiToken();
        } elseif ($agentApiToken !== '') {
            $node->setAgentApiToken($agentApiToken, $this->secretsCrypto);
        }

        $this->entityManager->flush();
        $this->auditLogger->log($actor, 'node.agent.settings_updated', [
            'node_id' => $node->getId(),
            'agent_base_url' => $agentBaseUrl !== '' ? $agentBaseUrl : null,
            'agent_api_token_updated' => $agentApiToken !== '' ? true : null,
            'agent_api_token_cleared' => $clearToken ?: null,
        ]);

        $connectivityError = $this->checkAgentConnectivity($agentBaseUrl);

        return $this->renderNodesTable('Agent settings updated.', $connectivityError);
    }

    #[Route(path: '/{id}/disk-protection-override', name: 'admin_nodes_disk_protection_override', methods: ['POST'])]
    public function updateDiskProtectionOverride(Request $request, string $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $node = $this->agentRepository->find($id);
        if ($node === null) {
            return new Response('Node not found.', Response::HTTP_NOT_FOUND);
        }

        $minutes = (int) $request->request->get('override_minutes', 0);
        $previousOverride = $node->getNodeDiskProtectionOverrideUntil();
        $overrideUntil = null;
        $notice = 'Disk protection override cleared.';

        if ($minutes > 0) {
            $overrideUntil = (new \DateTimeImmutable())->modify(sprintf('+%d minutes', $minutes));
            $notice = sprintf('Disk protection override active for %d minutes.', $minutes);
        }

        $node->setNodeDiskProtectionOverrideUntil($overrideUntil);
        $this->entityManager->persist($node);
        $this->auditLogger->log($actor, 'node.disk.protection_override_updated', [
            'node_id' => $node->getId(),
            'previous_override_until' => $previousOverride?->format(DATE_RFC3339),
            'override_until' => $overrideUntil?->format(DATE_RFC3339),
            'override_minutes' => $minutes,
        ]);
        $this->entityManager->flush();

        return $this->renderNodesTable($notice);
    }

    #[Route(path: '/update', name: 'admin_nodes_update_all', methods: ['POST'])]
    public function updateAgents(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $nodes = $this->agentRepository->findBy([], ['updatedAt' => 'DESC']);
        $latestVersion = $this->releaseChecker->getLatestVersion();
        $updateJobs = $this->buildUpdateJobIndex($nodes);

        $this->queueAgentUpdates($nodes, $latestVersion, $updateJobs, $actor);

        return $this->renderNodesTable();
    }

    #[Route(path: '/{id}/update', name: 'admin_nodes_update_one', methods: ['POST'])]
    public function updateAgent(Request $request, string $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $node = $this->agentRepository->find($id);
        if ($node === null) {
            return new Response('Node not found.', Response::HTTP_NOT_FOUND);
        }

        $latestVersion = $this->releaseChecker->getLatestVersion();
        $updateJobs = $this->buildUpdateJobIndex([$node]);
        $this->queueAgentUpdates([$node], $latestVersion, $updateJobs, $actor);

        return $this->renderNodesTable();
    }

    #[Route(path: '/{id}/update-check', name: 'admin_nodes_update_check', methods: ['POST'])]
    public function updateCheck(Request $request, string $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $node = $this->agentRepository->find($id);
        if ($node === null) {
            return new Response('Node not found.', Response::HTTP_NOT_FOUND);
        }

        $job = new Job('server.update.check', [
            'agent_id' => $node->getId(),
        ]);
        $this->entityManager->persist($job);
        $this->auditLogger->log($actor, 'node.server_update_check_queued', [
            'node_id' => $node->getId(),
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();

        return $this->renderNodesTable('Update check queued.');
    }

    #[Route(path: '/{id}/update-run', name: 'admin_nodes_update_run', methods: ['POST'])]
    public function updateRun(Request $request, string $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $node = $this->agentRepository->find($id);
        if ($node === null) {
            return new Response('Node not found.', Response::HTTP_NOT_FOUND);
        }

        $job = new Job('server.update.run', [
            'agent_id' => $node->getId(),
        ]);
        $this->entityManager->persist($job);
        $this->auditLogger->log($actor, 'node.server_update_run_queued', [
            'node_id' => $node->getId(),
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();

        return $this->renderNodesTable('Update run queued.');
    }

    #[Route(path: '/{id}/reboot', name: 'admin_nodes_reboot', methods: ['POST'])]
    public function reboot(Request $request, string $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $node = $this->agentRepository->find($id);
        if ($node === null) {
            return new Response('Node not found.', Response::HTTP_NOT_FOUND);
        }

        $job = new Job('server.reboot.run', [
            'agent_id' => $node->getId(),
        ]);
        $this->entityManager->persist($job);
        $this->auditLogger->log($actor, 'node.reboot_queued', [
            'node_id' => $node->getId(),
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();

        return $this->renderNodesTable('Reboot queued.');
    }

    #[Route(path: '/{id}/reboot-check', name: 'admin_nodes_reboot_check', methods: ['POST'])]
    public function rebootCheck(Request $request, string $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $node = $this->agentRepository->find($id);
        if ($node === null) {
            return new Response('Node not found.', Response::HTTP_NOT_FOUND);
        }

        $job = new Job('server.reboot.check_required', [
            'agent_id' => $node->getId(),
        ]);
        $this->entityManager->persist($job);
        $this->auditLogger->log($actor, 'node.reboot_check_queued', [
            'node_id' => $node->getId(),
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();

        return $this->renderNodesTable('Reboot check queued.');
    }

    #[Route(path: '/{id}/delete', name: 'admin_nodes_delete', methods: ['POST'])]
    public function deleteNode(Request $request, string $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $node = $this->agentRepository->find($id);
        if ($node === null) {
            return new Response('Node not found.', Response::HTTP_NOT_FOUND);
        }

        $usage = $this->buildNodeUsage($node);
        $usageMessage = $this->formatNodeUsageMessage($usage);
        if ($usageMessage !== null) {
            return $this->renderNodesTable(null, $usageMessage);
        }

        $this->removeNodeArtifacts($node);

        $this->auditLogger->log($actor, 'node.deleted', [
            'node_id' => $node->getId(),
            'name' => $node->getName(),
        ]);

        $this->entityManager->remove($node);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_NO_CONTENT, [
            'HX-Redirect' => '/admin/nodes',
        ]);
    }

    #[Route(path: '/provision', name: 'admin_nodes_provision_all', methods: ['POST'])]
    public function provisionAll(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $nodes = $this->agentRepository->findBy([], ['updatedAt' => 'DESC']);
        $queued = $this->queueProvisionJobs($nodes, $actor);

        if ($queued['nodes'] === 0) {
            return $this->renderNodesTable(null, 'No nodes with assigned roles to provision.');
        }

        $notice = sprintf(
            'Provisioning queued for %d node%s (%d job%s).',
            $queued['nodes'],
            $queued['nodes'] === 1 ? '' : 's',
            $queued['jobs'],
            $queued['jobs'] === 1 ? '' : 's',
        );

        return $this->renderNodesTable($notice);
    }

    #[Route(path: '/{id}/provision', name: 'admin_nodes_provision_one', methods: ['POST'])]
    public function provisionNode(Request $request, string $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $node = $this->agentRepository->find($id);
        if ($node === null) {
            return new Response('Node not found.', Response::HTTP_NOT_FOUND);
        }

        $queued = $this->queueProvisionJobs([$node], $actor);
        if ($queued['nodes'] === 0) {
            return $this->renderNodesTable(null, 'Assign at least one role before provisioning.');
        }

        $notice = sprintf(
            'Provisioning queued for %s (%d job%s).',
            $node->getName() ?? $node->getId(),
            $queued['jobs'],
            $queued['jobs'] === 1 ? '' : 's',
        );

        return $this->renderNodesTable($notice);
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->isAdmin();
    }

    private function buildSummary(array $nodes, ?string $latestVersion): array
    {
        $summary = [
            'total' => count($nodes),
            'online' => 0,
            'stale' => 0,
            'offline' => 0,
            'updates' => 0,
            'latestVersion' => $latestVersion,
        ];

        foreach ($nodes as $node) {
            $status = $this->resolveNodeStatus($node);
            $summary[$status]++;
            $updateAvailable = $this->releaseChecker->isUpdateAvailable(
                $node->getLastHeartbeatVersion(),
                $latestVersion,
            );
            if ($updateAvailable) {
                $summary['updates']++;
            }
        }

        return $summary;
    }

    private function renderNodesTable(?string $notice = null, ?string $error = null): Response
    {
        $nodes = $this->agentRepository->findBy([], ['updatedAt' => 'DESC']);
        $latestVersion = $this->releaseChecker->getLatestVersion();
        $updateJobs = $this->buildUpdateJobIndex($nodes);
        $serverUpdateJobs = $this->buildServerUpdateJobIndex($nodes);
        $ddosStatuses = $this->buildDdosStatusIndex($nodes);

        return new Response($this->twig->render('admin/nodes/_table.html.twig', [
            'nodes' => $this->normalizeNodes($nodes, $latestVersion, $updateJobs, $serverUpdateJobs, $ddosStatuses),
            'roleOptions' => self::ROLE_OPTIONS,
            'updateChannel' => $this->releaseChecker->getChannel(),
            'notice' => $notice,
            'error' => $error,
        ]));
    }

    /**
     * @param \App\Module\Core\Domain\Entity\Agent[] $nodes
     * @return array{nodes: int, jobs: int}
     */
    private function queueProvisionJobs(array $nodes, User $actor): array
    {
        $queuedNodes = 0;
        $queuedJobs = 0;

        foreach ($nodes as $node) {
            $roles = $this->normalizeProvisionRoles($node->getRoles());
            if ($roles === []) {
                continue;
            }

            $queuedNodes++;
            foreach ($roles as $role) {
                $job = new Job('role.ensure_base', [
                    'agent_id' => $node->getId(),
                    'role' => $role,
                ]);
                $this->entityManager->persist($job);
                $queuedJobs++;
            }

            $this->auditLogger->log($actor, 'node.provision_queued', [
                'node_id' => $node->getId(),
                'roles' => $roles,
            ]);
        }

        if ($queuedJobs > 0) {
            $this->entityManager->flush();
        }

        return [
            'nodes' => $queuedNodes,
            'jobs' => $queuedJobs,
        ];
    }

    private function resolveStatus(?\DateTimeImmutable $lastHeartbeatAt): string
    {
        if ($lastHeartbeatAt === null) {
            return 'offline';
        }

        $now = new \DateTimeImmutable();
        $minutes = (int) floor(($now->getTimestamp() - $lastHeartbeatAt->getTimestamp()) / 60);

        if ($minutes <= 2) {
            return 'online';
        }

        if ($minutes <= 10) {
            return 'stale';
        }

        return 'offline';
    }

    private function resolveNodeStatus(\App\Module\Core\Domain\Entity\Agent $node): string
    {
        $status = $node->getStatus();
        if ($status !== '') {
            return $status;
        }

        return $this->resolveStatus($node->getLastHeartbeatAt());
    }

    private function checkAgentConnectivity(string $agentBaseUrl): ?string
    {
        if (trim($agentBaseUrl) === '') {
            return null;
        }

        $parts = parse_url($agentBaseUrl);
        if (!is_array($parts)) {
            return sprintf('Agent Base URL "%s" could not be parsed.', $agentBaseUrl);
        }

        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';
        $port = $parts['port'] ?? null;

        if ($scheme === '' || $host === '') {
            return sprintf('Agent Base URL "%s" must include scheme and host.', $agentBaseUrl);
        }

        if ($port === null) {
            $port = $scheme === 'https' ? 443 : 80;
        }

        $transport = $scheme === 'https' ? 'ssl' : 'tcp';
        $target = sprintf('%s://%s:%d', $transport, $host, $port);

        $errno = 0;
        $errstr = '';
        $connection = @stream_socket_client($target, $errno, $errstr, 2.0);

        if (!is_resource($connection)) {
            $details = $errstr !== '' ? $errstr : 'unknown error';
            return sprintf('Agent Base URL "%s" is not reachable from the panel (%s).', $agentBaseUrl, $details);
        }

        fclose($connection);

        return null;
    }

    /**
     * @return array{instances: int, webspaces: int, ts3_instances: int, ts6_instances: int}
     */
    private function buildNodeUsage(\App\Module\Core\Domain\Entity\Agent $node): array
    {
        return [
            'instances' => $this->instanceRepository->count(['node' => $node]),
            'webspaces' => $this->webspaceRepository->count(['node' => $node]),
            'ts3_instances' => $this->ts3InstanceRepository->count(['node' => $node]),
            'ts6_instances' => $this->ts6InstanceRepository->count(['node' => $node]),
        ];
    }

    /**
     * @param array{instances: int, webspaces: int, ts3_instances: int, ts6_instances: int} $usage
     */
    private function hasNodeUsage(array $usage): bool
    {
        return array_sum($usage) > 0;
    }

    /**
     * @param array{instances: int, webspaces: int, ts3_instances: int, ts6_instances: int} $usage
     */
    private function formatNodeUsageMessage(array $usage): ?string
    {
        $labels = [
            'instances' => 'game servers',
            'webspaces' => 'webspaces',
            'ts3_instances' => 'TS3 instances',
            'ts6_instances' => 'TS6 instances',
        ];
        $parts = [];

        foreach ($usage as $key => $count) {
            if ($count > 0) {
                $label = $labels[$key] ?? $key;
                $parts[] = sprintf('%s (%d)', $label, $count);
            }
        }

        if ($parts === []) {
            return null;
        }

        return sprintf('Node cannot be deleted while it still has: %s.', implode(', ', $parts));
    }

    private function removeNodeArtifacts(\App\Module\Core\Domain\Entity\Agent $node): void
    {
        $firewallState = $this->firewallStateRepository->findOneBy(['node' => $node]);
        if ($firewallState !== null) {
            $this->entityManager->remove($firewallState);
        }

        $this->metricSampleRepository->createQueryBuilder('sample')
            ->delete()
            ->where('sample.agent = :agent')
            ->setParameter('agent', $node)
            ->getQuery()
            ->execute();
    }

    private function normalizeNodes(
        array $nodes,
        ?string $latestVersion,
        array $updateJobs = [],
        array $serverUpdateJobs = [],
        array $ddosStatuses = [],
    ): array
    {
        $now = new \DateTimeImmutable();

        return array_map(function ($node) use ($latestVersion, $updateJobs, $serverUpdateJobs, $ddosStatuses, $now): array {
            $stats = $node->getLastHeartbeatStats() ?? [];
            $serviceStats = $stats['services'] ?? [];
            $rebootRequired = $stats['reboot_required'] ?? null;
            $osProvider = $stats['os_provider'] ?? null;
            $roles = $node->getRoles();
            $normalizedRoles = $this->normalizeRoles($roles, $stats);
            $currentVersion = $node->getLastHeartbeatVersion();
            if ($currentVersion === null || $currentVersion === '') {
                $statsVersion = $stats['version'] ?? null;
                if (is_string($statsVersion) && $statsVersion !== '') {
                    $currentVersion = $statsVersion;
                }
            }
            $updateJob = $updateJobs[$node->getId()] ?? null;
            $serverUpdateJob = $serverUpdateJobs[$node->getId()] ?? null;
            $serverUpdateAvailable = null;
            $serverUpdateMessage = null;
            if ($serverUpdateJob instanceof Job) {
                $output = $serverUpdateJob->getResult()?->getOutput() ?? [];
                $serverUpdateMessage = is_string($output['message'] ?? null) ? $output['message'] : null;
                $updatesAvailableRaw = $output['updates_available'] ?? null;
                if (is_string($updatesAvailableRaw)) {
                    $normalized = strtolower($updatesAvailableRaw);
                    if (in_array($normalized, ['true', 'false'], true)) {
                        $serverUpdateAvailable = $normalized === 'true';
                    }
                }
            }

            $ddosStatus = $ddosStatuses[$node->getId()] ?? null;
            $diskStat = $this->nodeDiskProtectionService->getDiskStat($node);
            $diskProtectActive = $this->nodeDiskProtectionService->isProtectionActive($node, $now);
            $diskOverrideActive = $this->nodeDiskProtectionService->isOverrideActive($node, $now);
            $agentBaseUrl = $node->getAgentBaseUrl();
            $agentTokenConfigured = $node->getAgentApiTokenEncrypted() !== '';

            return [
                'id' => $node->getId(),
                'name' => $node->getName(),
                'roles' => $normalizedRoles,
                'roleKeys' => array_map('strtolower', $normalizedRoles),
                'status' => $this->resolveNodeStatus($node),
                'lastHeartbeatAt' => $node->getLastHeartbeatAt(),
                'lastSeenAt' => $node->getLastSeenAt(),
                'lastHeartbeatIp' => $node->getLastHeartbeatIp(),
                'lastHeartbeatVersion' => $currentVersion,
                'osProvider' => is_string($osProvider) ? $osProvider : null,
                'rebootRequired' => is_bool($rebootRequired) ? $rebootRequired : null,
                'services' => is_array($serviceStats) ? $serviceStats : [],
                'disk' => [
                    'free_bytes' => $diskStat['free_bytes'] ?? null,
                    'free_percent' => $diskStat['free_percent'] ?? null,
                    'free_human' => isset($diskStat['free_bytes']) ? $this->diskUsageFormatter->formatBytes((int) $diskStat['free_bytes']) : null,
                    'checked_at' => $diskStat['checked_at'] ?? null,
                    'protect_active' => $diskProtectActive,
                    'override_active' => $diskOverrideActive,
                    'override_until' => $node->getNodeDiskProtectionOverrideUntil(),
                    'scan_interval' => $node->getDiskScanIntervalSeconds(),
                    'warning_percent' => $node->getDiskWarningPercent(),
                    'hard_block_percent' => $node->getDiskHardBlockPercent(),
                    'protect_threshold' => $node->getNodeDiskProtectionThresholdPercent(),
                ],
                'agent' => [
                    'base_url' => $agentBaseUrl !== '' ? $agentBaseUrl : null,
                    'token_configured' => $agentTokenConfigured,
                ],
                'updateAvailable' => $this->releaseChecker->isUpdateAvailable($currentVersion, $latestVersion),
                'latestVersion' => $latestVersion,
                'updatedAt' => $node->getUpdatedAt(),
                'ddos' => $ddosStatus === null ? null : [
                    'attackActive' => $ddosStatus->isAttackActive(),
                    'reportedAt' => $ddosStatus->getReportedAt(),
                    'mode' => $ddosStatus->getMode(),
                ],
                'updateJob' => $updateJob === null ? null : [
                    'id' => $updateJob->getId(),
                    'status' => $updateJob->getStatus()->value,
                    'createdAt' => $updateJob->getCreatedAt(),
                    'updatedAt' => $updateJob->getUpdatedAt(),
                    'resultStatus' => $updateJob->getResult()?->getStatus()->value,
                    'resultMessage' => $updateJob->getResult()?->getOutput()['message'] ?? null,
                ],
                'serverUpdate' => $serverUpdateJob === null ? null : [
                    'id' => $serverUpdateJob->getId(),
                    'type' => $serverUpdateJob->getType(),
                    'status' => $serverUpdateJob->getStatus()->value,
                    'createdAt' => $serverUpdateJob->getCreatedAt(),
                    'updatedAt' => $serverUpdateJob->getUpdatedAt(),
                    'resultStatus' => $serverUpdateJob->getResult()?->getStatus()->value,
                    'resultMessage' => $serverUpdateMessage,
                    'updatesAvailable' => $serverUpdateAvailable,
                ],
            ];
        }, $nodes);
    }

    /**
     * @param \App\Module\Core\Domain\Entity\Agent[] $nodes
     * @return array<string, \App\Module\Core\Domain\Entity\DdosStatus>
     */
    private function buildDdosStatusIndex(array $nodes): array
    {
        $statuses = $this->ddosStatusRepository->findByNodes($nodes);
        $index = [];
        foreach ($statuses as $status) {
            $index[$status->getNode()->getId()] = $status;
        }
        return $index;
    }

    /**
     * @param \App\Module\Core\Domain\Entity\Agent[] $nodes
     * @return array<string, Job>
     */
    private function buildUpdateJobIndex(array $nodes): array
    {
        if ($nodes === []) {
            return [];
        }

        $agentIds = array_map(static fn ($node): string => $node->getId(), $nodes);
        $limit = max(50, count($nodes) * 4);
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
     * @param \App\Module\Core\Domain\Entity\Agent[] $nodes
     * @return array<string, Job>
     */
    private function buildServerUpdateJobIndex(array $nodes): array
    {
        if ($nodes === []) {
            return [];
        }

        $agentIds = array_map(static fn ($node): string => $node->getId(), $nodes);
        $limit = max(50, count($nodes) * 4);
        $jobs = array_merge(
            $this->jobRepository->findLatestByType('server.update.check', $limit),
            $this->jobRepository->findLatestByType('server.update.run', $limit),
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
     * @param \App\Module\Core\Domain\Entity\Agent[] $nodes
     * @param array<string, Job> $existingJobs
     */
    private function queueAgentUpdates(array $nodes, ?string $latestVersion, array $existingJobs, User $actor): void
    {
        foreach ($nodes as $node) {
            $payload = $this->buildAgentUpdatePayload($node, $latestVersion);
            if ($payload === null) {
                continue;
            }

            $existingJob = $existingJobs[$node->getId()] ?? null;
            if ($existingJob !== null && in_array($existingJob->getStatus()->value, ['queued', 'running'], true)) {
                continue;
            }

            $job = new Job($this->resolveAgentUpdateJobType($node), $payload);
            $this->entityManager->persist($job);

            $this->auditLogger->log($actor, 'node.agent_update_queued', [
                'node_id' => $node->getId(),
                'job_id' => $job->getId(),
                'version' => $payload['version'],
                'asset_name' => $payload['asset_name'],
            ]);
        }

        $this->entityManager->flush();
    }

    private function buildAgentUpdatePayload(\App\Module\Core\Domain\Entity\Agent $node, ?string $latestVersion): ?array
    {
        $currentVersion = $node->getLastHeartbeatVersion();

        $stats = $node->getLastHeartbeatStats() ?? [];
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

        if ($this->releaseChecker->isUpdateAvailable($currentVersion, $version) !== true) {
            return null;
        }

        return [
            'agent_id' => $node->getId(),
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

    private function resolveAgentUpdateJobType(\App\Module\Core\Domain\Entity\Agent $node): string
    {
        $stats = $node->getLastHeartbeatStats() ?? [];
        $os = is_string($stats['os'] ?? null) ? strtolower($stats['os']) : '';

        return $os === 'windows' ? 'agent.self_update' : 'agent.update';
    }

    private function normalizeRoles(array $roles, array $stats): array
    {
        if ($roles !== []) {
            return $roles;
        }

        $statRoles = $stats['roles'] ?? null;
        if (is_array($statRoles)) {
            $normalized = array_values(array_filter(array_map(static function ($role): ?string {
                if (!is_string($role)) {
                    return null;
                }

                $value = trim($role);
                return $value !== '' ? $value : null;
            }, $statRoles)));

            if ($normalized !== []) {
                return $normalized;
            }
        }

        $singleRole = $stats['role'] ?? null;
        if (is_string($singleRole) && $singleRole !== '') {
            return [$singleRole];
        }

        return [];
    }

    /**
     * @param string[] $roles
     * @return string[]
     */
    private function normalizeRoleSelection(array $roles): array
    {
        $selected = array_fill_keys(array_map('strtolower', self::ROLE_OPTIONS), false);

        foreach ($roles as $role) {
            if (!is_string($role)) {
                continue;
            }

            $value = trim($role);
            if ($value === '') {
                continue;
            }

            $lower = strtolower($value);
            if (array_key_exists($lower, $selected)) {
                $selected[$lower] = true;
            }
        }

        $normalized = [];
        foreach (self::ROLE_OPTIONS as $option) {
            if ($selected[strtolower($option)] ?? false) {
                $normalized[] = $option;
            }
        }

        return $normalized;
    }

    /**
     * @param string[] $roles
     * @return string[]
     */
    private function normalizeProvisionRoles(array $roles): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(static function ($role): ?string {
            if (!is_string($role)) {
                return null;
            }

            $value = trim($role);
            return $value !== '' ? $value : null;
        }, $roles))));

        return $normalized;
    }

    /**
     * @param string[] $roles
     * @return string[]
     */
    private function extractUnknownRoles(array $roles): array
    {
        $allowed = array_map('strtolower', self::ROLE_OPTIONS);
        $unknown = [];

        foreach ($roles as $role) {
            if (!is_string($role)) {
                continue;
            }

            $value = trim($role);
            if ($value === '') {
                continue;
            }

            if (!in_array(strtolower($value), $allowed, true)) {
                $unknown[] = $value;
            }
        }

        return array_values(array_unique($unknown));
    }

    /**
     * @param string[] $current
     * @param string[] $next
     */
    private function rolesChanged(array $current, array $next): bool
    {
        $currentValues = array_values($current);
        $nextValues = array_values($next);
        sort($currentValues);
        sort($nextValues);

        return $currentValues !== $nextValues;
    }
}
