<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\Job;
use App\Enum\UserType;
use App\Repository\AgentRepository;
use App\Repository\JobRepository;
use App\Service\AgentReleaseChecker;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/nodes')]
final class AdminNodeController
{
    private const ROLE_OPTIONS = ['Control', 'Worker', 'Edge', 'Storage', 'Gateway'];

    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly JobRepository $jobRepository,
        private readonly Environment $twig,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly AgentReleaseChecker $releaseChecker,
    ) {
    }

    #[Route(path: '', name: 'admin_nodes', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $nodes = $this->agentRepository->findBy([], ['updatedAt' => 'DESC']);
        $latestVersion = $this->releaseChecker->getLatestVersion();
        $summary = $this->buildSummary($nodes, $latestVersion);
        $updateJobs = $this->buildUpdateJobIndex($nodes);

        return new Response($this->twig->render('admin/nodes/index.html.twig', [
            'nodes' => $this->normalizeNodes($nodes, $latestVersion, $updateJobs),
            'summary' => $summary,
            'roleOptions' => self::ROLE_OPTIONS,
            'activeNav' => 'nodes',
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

        return new Response($this->twig->render('admin/nodes/_table.html.twig', [
            'nodes' => $this->normalizeNodes($nodes, $latestVersion, $updateJobs),
            'roleOptions' => self::ROLE_OPTIONS,
        ]));
    }

    #[Route(path: '/{id}/roles', name: 'admin_nodes_roles', methods: ['POST'])]
    public function updateRoles(Request $request, string $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
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

        if ($this->rolesChanged($existingStoredRoles, $rolesToStore)) {
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
        }

        $nodes = $this->agentRepository->findBy([], ['updatedAt' => 'DESC']);
        $latestVersion = $this->releaseChecker->getLatestVersion();
        $updateJobs = $this->buildUpdateJobIndex($nodes);

        return new Response($this->twig->render('admin/nodes/_table.html.twig', [
            'nodes' => $this->normalizeNodes($nodes, $latestVersion, $updateJobs),
            'roleOptions' => self::ROLE_OPTIONS,
        ]));
    }

    #[Route(path: '/update', name: 'admin_nodes_update_all', methods: ['POST'])]
    public function updateAgents(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $nodes = $this->agentRepository->findBy([], ['updatedAt' => 'DESC']);
        $latestVersion = $this->releaseChecker->getLatestVersion();
        $updateJobs = $this->buildUpdateJobIndex($nodes);

        $this->queueAgentUpdates($nodes, $latestVersion, $updateJobs, $actor);

        $nodes = $this->agentRepository->findBy([], ['updatedAt' => 'DESC']);
        $latestVersion = $this->releaseChecker->getLatestVersion();
        $updateJobs = $this->buildUpdateJobIndex($nodes);

        return new Response($this->twig->render('admin/nodes/_table.html.twig', [
            'nodes' => $this->normalizeNodes($nodes, $latestVersion, $updateJobs),
            'roleOptions' => self::ROLE_OPTIONS,
        ]));
    }

    #[Route(path: '/{id}/update', name: 'admin_nodes_update_one', methods: ['POST'])]
    public function updateAgent(Request $request, string $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $node = $this->agentRepository->find($id);
        if ($node === null) {
            return new Response('Node not found.', Response::HTTP_NOT_FOUND);
        }

        $latestVersion = $this->releaseChecker->getLatestVersion();
        $updateJobs = $this->buildUpdateJobIndex([$node]);
        $this->queueAgentUpdates([$node], $latestVersion, $updateJobs, $actor);

        $nodes = $this->agentRepository->findBy([], ['updatedAt' => 'DESC']);
        $latestVersion = $this->releaseChecker->getLatestVersion();
        $updateJobs = $this->buildUpdateJobIndex($nodes);

        return new Response($this->twig->render('admin/nodes/_table.html.twig', [
            'nodes' => $this->normalizeNodes($nodes, $latestVersion, $updateJobs),
            'roleOptions' => self::ROLE_OPTIONS,
        ]));
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->getType() === UserType::Admin;
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

    private function resolveNodeStatus(\App\Entity\Agent $node): string
    {
        $status = $node->getStatus();
        if ($status !== '') {
            return $status;
        }

        return $this->resolveStatus($node->getLastHeartbeatAt());
    }

    private function normalizeNodes(array $nodes, ?string $latestVersion, array $updateJobs = []): array
    {
        return array_map(function ($node) use ($latestVersion, $updateJobs): array {
            $stats = $node->getLastHeartbeatStats() ?? [];
            $roles = $node->getRoles();
            $normalizedRoles = $this->normalizeRoles($roles, $stats);
            $currentVersion = $node->getLastHeartbeatVersion();
            $updateJob = $updateJobs[$node->getId()] ?? null;

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
                'updateAvailable' => $this->releaseChecker->isUpdateAvailable($currentVersion, $latestVersion),
                'latestVersion' => $latestVersion,
                'updatedAt' => $node->getUpdatedAt(),
                'updateJob' => $updateJob === null ? null : [
                    'id' => $updateJob->getId(),
                    'status' => $updateJob->getStatus()->value,
                    'createdAt' => $updateJob->getCreatedAt(),
                    'updatedAt' => $updateJob->getUpdatedAt(),
                    'resultStatus' => $updateJob->getResult()?->getStatus()->value,
                    'resultMessage' => $updateJob->getResult()?->getOutput()['message'] ?? null,
                ],
            ];
        }, $nodes);
    }

    /**
     * @param \App\Entity\Agent[] $nodes
     * @return array<string, Job>
     */
    private function buildUpdateJobIndex(array $nodes): array
    {
        if ($nodes === []) {
            return [];
        }

        $agentIds = array_map(static fn ($node): string => $node->getId(), $nodes);
        $jobs = $this->jobRepository->findLatestByType('agent.update', max(50, count($nodes) * 4));
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
     * @param \App\Entity\Agent[] $nodes
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

            $job = new Job('agent.update', $payload);
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

    private function buildAgentUpdatePayload(\App\Entity\Agent $node, ?string $latestVersion): ?array
    {
        if ($latestVersion === null || $latestVersion === '') {
            return null;
        }

        $currentVersion = $node->getLastHeartbeatVersion();
        if ($this->releaseChecker->isUpdateAvailable($currentVersion, $latestVersion) !== true) {
            return null;
        }

        $stats = $node->getLastHeartbeatStats() ?? [];
        $os = is_string($stats['os'] ?? null) ? strtolower($stats['os']) : '';
        $arch = is_string($stats['arch'] ?? null) ? strtolower($stats['arch']) : '';

        $assetName = $this->resolveAgentAssetName($os, $arch);
        if ($assetName === null) {
            return null;
        }

        $repository = $this->releaseChecker->getRepository();
        if ($repository === '') {
            return null;
        }

        $downloadUrl = sprintf('https://github.com/%s/releases/download/%s/%s', $repository, $latestVersion, $assetName);
        $checksumsUrl = sprintf('https://github.com/%s/releases/download/%s/checksums-agent.txt', $repository, $latestVersion);

        return [
            'agent_id' => $node->getId(),
            'download_url' => $downloadUrl,
            'checksums_url' => $checksumsUrl,
            'asset_name' => $assetName,
            'version' => $latestVersion,
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

        return ['Worker'];
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
