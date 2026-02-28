<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\Ddos\DdosCredentialManager;
use App\Module\Core\Domain\Entity\SecurityEvent;
use App\Module\Core\Domain\Entity\SecurityPolicyRevision;
use App\Module\Core\Domain\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\DdosProviderCredentialRepository;
use App\Repository\FirewallStateRepository;
use App\Repository\JobRepository;
use App\Repository\SecurityEventRepository;
use App\Repository\SecurityPolicyRevisionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/security')]
final class AdminSecurityController
{
    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly FirewallStateRepository $firewallStateRepository,
        private readonly JobRepository $jobRepository,
        private readonly DdosProviderCredentialRepository $credentialRepository,
        private readonly SecurityPolicyRevisionRepository $policyRevisionRepository,
        private readonly SecurityEventRepository $securityEventRepository,
        private readonly DdosCredentialManager $credentialManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route(path: '', name: 'admin_security', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $admin = $this->requireAdmin($request);

        return $this->renderPage($admin, $request);
    }

    #[Route(path: '/ddos', name: 'admin_security_ddos', methods: ['POST'])]
    public function updateDdos(Request $request): Response
    {
        $admin = $this->requireAdmin($request);

        $provider = trim((string) $request->request->get('provider', ''));
        $apiKey = trim((string) $request->request->get('api_key', ''));

        $errors = [];
        if ($provider === '') {
            $errors[] = 'Provider is required.';
        }

        if ($apiKey === '') {
            $errors[] = 'API key is required.';
        }

        if ($errors !== []) {
            return $this->renderPage($admin, $request, $errors, Response::HTTP_BAD_REQUEST);
        }

        $this->credentialManager->storeCredential($admin, $provider, $apiKey, $admin);
        $this->entityManager->flush();

        return new RedirectResponse('/admin/security?ddos=updated');
    }

    #[Route(path: '/firewall/{id}', name: 'admin_security_firewall', methods: ['POST'])]
    public function updateFirewall(Request $request, string $id): Response
    {
        $admin = $this->requireAdmin($request);

        $agent = $this->agentRepository->find($id);
        if ($agent === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $desiredPorts = $this->parsePorts((string) $request->request->get('ports', ''));
        $currentState = $this->firewallStateRepository->findOneBy(['node' => $agent]);
        $currentPorts = $currentState?->getPorts() ?? [];

        $toOpen = array_values(array_diff($desiredPorts, $currentPorts));
        $toClose = array_values(array_diff($currentPorts, $desiredPorts));
        $dryRun = $request->request->get('dry_run') === '1';

        $revision = $this->buildPolicyRevision(
            $agent,
            'firewall',
            [
                'desired_ports' => $desiredPorts,
                'open_ports' => $toOpen,
                'close_ports' => $toClose,
                'dry_run' => $dryRun,
            ],
            $admin,
        );

        if ($dryRun) {
            $revision->markPreview();
            $this->auditLogger->log($admin, 'firewall.policy.previewed', [
                'agent_id' => $agent->getId(),
                'policy_revision_id' => $revision->getId(),
            ]);
        } elseif ($toOpen === [] && $toClose === []) {
            $revision->markApplied();
            $this->auditLogger->log($admin, 'firewall.policy.noop', [
                'agent_id' => $agent->getId(),
                'policy_revision_id' => $revision->getId(),
            ]);
        }

        $existingJobs = $this->buildFirewallJobIndex([$agent]);
        $latestJob = $existingJobs[$agent->getId()] ?? null;
        $hasPending = $latestJob !== null && in_array($latestJob->getStatus()->value, ['queued', 'running'], true);

        if ($toOpen !== [] && !$dryRun) {
            if (!$hasPending) {
                $job = new \App\Module\Core\Domain\Entity\Job('firewall.open_ports', [
                    'agent_id' => $agent->getId(),
                    'ports' => implode(',', array_map('strval', $toOpen)),
                    'policy_revision_id' => $revision->getId(),
                ]);
                $this->entityManager->persist($job);
                $this->auditLogger->log($admin, 'firewall.open_ports_queued', [
                    'agent_id' => $agent->getId(),
                    'ports' => $toOpen,
                    'job_id' => $job->getId(),
                    'policy_revision_id' => $revision->getId(),
                ]);
            }
        }

        if ($toClose !== [] && !$dryRun) {
            if (!$hasPending) {
                $job = new \App\Module\Core\Domain\Entity\Job('firewall.close_ports', [
                    'agent_id' => $agent->getId(),
                    'ports' => implode(',', array_map('strval', $toClose)),
                    'policy_revision_id' => $revision->getId(),
                ]);
                $this->entityManager->persist($job);
                $this->auditLogger->log($admin, 'firewall.close_ports_queued', [
                    'agent_id' => $agent->getId(),
                    'ports' => $toClose,
                    'job_id' => $job->getId(),
                    'policy_revision_id' => $revision->getId(),
                ]);
            }
        }

        $this->entityManager->flush();

        $query = sprintf('/admin/security?firewall=%s', $agent->getId());
        if ($dryRun) {
            $query .= '&preview=1';
        }

        return new RedirectResponse($query);
    }

    #[Route(path: '/fail2ban/{id}', name: 'admin_security_fail2ban', methods: ['POST'])]
    public function updateFail2ban(Request $request, string $id): Response
    {
        $admin = $this->requireAdmin($request);

        $agent = $this->agentRepository->find($id);
        if ($agent === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $payload = $this->parseFail2banPayload($request);
        $dryRun = $payload['dry_run'] ?? false;

        $revision = $this->buildPolicyRevision($agent, 'fail2ban', $payload, $admin);

        if (!$dryRun) {
            $job = new \App\Module\Core\Domain\Entity\Job('fail2ban.policy.apply', [
                'agent_id' => $agent->getId(),
                'policy_revision_id' => $revision->getId(),
                'policy' => $payload,
            ]);
            $this->entityManager->persist($job);
            $this->auditLogger->log($admin, 'fail2ban.policy.apply_queued', [
                'agent_id' => $agent->getId(),
                'job_id' => $job->getId(),
                'policy_revision_id' => $revision->getId(),
                'dry_run' => $dryRun,
            ]);
        } else {
            $revision->markPreview();
            $this->auditLogger->log($admin, 'fail2ban.policy.previewed', [
                'agent_id' => $agent->getId(),
                'policy_revision_id' => $revision->getId(),
            ]);
        }

        $this->entityManager->flush();

        $query = sprintf('/admin/security?fail2ban=%s', $agent->getId());
        if ($dryRun) {
            $query .= '&preview=1';
        }

        return new RedirectResponse($query);
    }

    #[Route(path: '/fail2ban/{id}/status', name: 'admin_security_fail2ban_status', methods: ['POST'])]
    public function queueFail2banStatus(Request $request, string $id): Response
    {
        $admin = $this->requireAdmin($request);

        $agent = $this->agentRepository->find($id);
        if ($agent === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $job = new \App\Module\Core\Domain\Entity\Job('fail2ban.status.check', [
            'agent_id' => $agent->getId(),
        ]);
        $this->entityManager->persist($job);
        $this->auditLogger->log($admin, 'fail2ban.status.check_queued', [
            'agent_id' => $agent->getId(),
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();

        return new RedirectResponse(sprintf('/admin/security?fail2ban=%s', $agent->getId()));
    }

    #[Route(path: '/ruleset/apply', name: 'admin_security_ruleset_apply', methods: ['POST'])]
    public function applyUnifiedRuleSet(Request $request): Response
    {
        $admin = $this->requireAdmin($request);

        $targetScope = trim((string) $request->request->get('target_scope', 'global'));
        $targetNodeId = trim((string) $request->request->get('target_node_id', ''));
        $rulesJson = trim((string) $request->request->get('rules_json', '[]'));

        try {
            $rules = json_decode($rulesJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->renderPage($admin, $request, ['Invalid unified rules JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        if (!is_array($rules)) {
            return $this->renderPage($admin, $request, ['Unified rules payload must be a JSON array.'], Response::HTTP_BAD_REQUEST);
        }

        $sanitizedRules = $this->sanitizeUnifiedRules($rules);
        if ($sanitizedRules === []) {
            return $this->renderPage($admin, $request, ['No valid unified rules to apply.'], Response::HTTP_BAD_REQUEST);
        }

        $targets = $targetScope === 'global' ? $this->agentRepository->findAll() : [];
        if ($targetScope === 'node') {
            $node = $this->agentRepository->find($targetNodeId);
            if ($node === null) {
                return $this->renderPage($admin, $request, ['Target node not found.'], Response::HTTP_NOT_FOUND);
            }
            $targets = [$node];
        }

        foreach ($targets as $targetNode) {
            $canonicalRules = $this->canonicalizeUnifiedRules($sanitizedRules);
            $rulesHash = hash('sha256', json_encode($canonicalRules) ?: '[]');
            $revision = $this->buildPolicyRevision($targetNode, 'unified_ruleset', ['rules' => $canonicalRules, 'hash' => $rulesHash], $admin);
            $this->cleanupLegacyDistributedSecurity($targetNode);
            $job = new \App\Module\Core\Domain\Entity\Job('security.ruleset.apply', [
                'agent_id' => $targetNode->getId(),
                'target' => $targetScope,
                'policy_revision_id' => $revision->getId(),
                'ruleset' => [
                    'version' => $revision->getVersion(),
                    'created_by' => $admin->getEmail(),
                    'hash' => $rulesHash,
                    'rules' => $canonicalRules,
                ],
            ]);
            $this->entityManager->persist($job);
        }

        $this->entityManager->flush();

        return new RedirectResponse('/admin/security?ruleset=applied');
    }

    #[Route(path: '/ruleset/{id}/rollback', name: 'admin_security_ruleset_rollback', methods: ['POST'])]
    public function rollbackUnifiedRuleSet(Request $request, string $id): Response
    {
        $admin = $this->requireAdmin($request);
        $agent = $this->agentRepository->find($id);
        if ($agent === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $revision = $this->buildPolicyRevision($agent, 'unified_ruleset', ['rollback' => true], $admin);
        $job = new \App\Module\Core\Domain\Entity\Job('security.ruleset.rollback', [
            'agent_id' => $agent->getId(),
            'policy_revision_id' => $revision->getId(),
        ]);
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return new RedirectResponse('/admin/security?ruleset=rollback');
    }

    private function renderPage(User $admin, Request $request, array $errors = [], int $status = Response::HTTP_OK): Response
    {
        $agents = $this->agentRepository->findBy([], ['updatedAt' => 'DESC']);
        $firewallJobs = $this->buildFirewallJobIndex($agents);
        $firewallRevisions = $this->indexPolicyRevisions($agents, 'firewall');
        $fail2banRevisions = $this->indexPolicyRevisions($agents, 'fail2ban');
        $unifiedRevisions = $this->indexPolicyRevisions($agents, 'unified_ruleset');
        $firewallNodes = array_map(function ($agent) use ($firewallJobs, $firewallRevisions): array {
            $state = $this->firewallStateRepository->findOneBy(['node' => $agent]);
            $ports = $state?->getPorts() ?? [];
            $rules = $state?->getRules() ?? [];
            sort($ports);
            $rules = $this->normalizeRules($rules);
            $job = $firewallJobs[$agent->getId()] ?? null;

            $revision = $firewallRevisions[$agent->getId()] ?? null;

            return [
                'id' => $agent->getId(),
                'name' => $agent->getName() ?? 'Unnamed node',
                'updatedAt' => $agent->getUpdatedAt(),
                'lastHeartbeatAt' => $agent->getLastHeartbeatAt(),
                'ports' => $ports,
                'rules' => $rules,
                'status' => $this->resolveAgentStatus($agent->getLastHeartbeatAt()),
                'job' => $job === null ? null : [
                    'id' => $job->getId(),
                    'type' => $job->getType(),
                    'status' => $job->getStatus()->value,
                    'createdAt' => $job->getCreatedAt(),
                    'updatedAt' => $job->getUpdatedAt(),
                    'resultStatus' => $job->getResult()?->getStatus()->value,
                    'resultMessage' => $job->getResult()?->getOutput()['message'] ?? null,
                ],
                'policy' => $revision === null ? null : [
                    'version' => $revision->getVersion(),
                    'status' => $revision->getStatus(),
                    'updatedAt' => $revision->getUpdatedAt(),
                    'appliedAt' => $revision->getAppliedAt(),
                ],
            ];
        }, $agents);

        $fail2banNodes = array_map(function ($agent) use ($fail2banRevisions, $unifiedRevisions): array {
            $revision = $fail2banRevisions[$agent->getId()] ?? null;
            $unifiedRevision = $unifiedRevisions[$agent->getId()] ?? null;
            $payload = $revision?->getPayload() ?? [];

            return [
                'id' => $agent->getId(),
                'name' => $agent->getName() ?? 'Unnamed node',
                'updatedAt' => $agent->getUpdatedAt(),
                'lastHeartbeatAt' => $agent->getLastHeartbeatAt(),
                'status' => $this->resolveAgentStatus($agent->getLastHeartbeatAt()),
                'policy' => $revision === null ? null : [
                    'version' => $revision->getVersion(),
                    'status' => $revision->getStatus(),
                    'updatedAt' => $revision->getUpdatedAt(),
                    'appliedAt' => $revision->getAppliedAt(),
                    'payload' => $payload,
                ],
                'unifiedPolicy' => $unifiedRevision === null ? null : [
                    'version' => $unifiedRevision->getVersion(),
                    'status' => $unifiedRevision->getStatus(),
                    'updatedAt' => $unifiedRevision->getUpdatedAt(),
                ],
            ];
        }, $agents);

        $credentials = $this->credentialRepository->findBy(['customer' => $admin], ['updatedAt' => 'DESC']);

        $eventFilters = $this->parseEventFilters($request);
        $events = $this->securityEventRepository->findFiltered(
            $eventFilters['from'],
            $eventFilters['to'],
            $eventFilters['ip'],
            $eventFilters['rule'],
            $eventFilters['source'],
        );
        $eventSummary = $this->summarizeEvents($events);

        return new Response($this->twig->render('admin/security/index.html.twig', [
            'activeNav' => 'security',
            'errors' => $errors,
            'firewallNodes' => $firewallNodes,
            'fail2banNodes' => $fail2banNodes,
            'credentials' => $credentials,
            'ddosUpdated' => $request->query->get('ddos') === 'updated',
            'firewallUpdated' => $request->query->get('firewall'),
            'fail2banUpdated' => $request->query->get('fail2ban'),
            'previewEnabled' => $request->query->get('preview') === '1',
            'securityEvents' => $events,
            'securityEventSummary' => $eventSummary,
            'securityEventFilters' => $eventFilters,
            'rulesetUpdated' => $request->query->get('ruleset'),
        ]), $status);
    }

    /**
     * @param array<int, mixed> $rules
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeUnifiedRules(array $rules): array
    {
        $sanitized = [];
        foreach ($rules as $idx => $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $type = strtolower(trim((string) ($rule['type'] ?? '')));
            $action = strtolower(trim((string) ($rule['action'] ?? '')));
            $port = (int) ($rule['port'] ?? 0);
            $priority = (int) ($rule['priority'] ?? 100);
            $enabled = filter_var($rule['enabled'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            $reason = trim((string) ($rule['reason'] ?? ''));
            $service = trim((string) (($rule['target']['service'] ?? '')));

            if (!in_array($type, ['firewall', 'fail2ban'], true) || !in_array($action, ['allow', 'block', 'ban'], true)) {
                continue;
            }
            if ($type === 'firewall' && ($port < 1 || $port > 65535)) {
                continue;
            }
            if ($service !== '' && preg_match('/[\*\+\?\[\]\{\}\(\)\\]/', $service)) {
                continue;
            }
            if ($reason !== '' && preg_match('/[\r\n]/', $reason)) {
                continue;
            }

            $sanitized[] = [
                'id' => (string) ($rule['id'] ?? sprintf('rule-%d', $idx + 1)),
                'type' => $type,
                'action' => $action,
                'protocol' => in_array(($rule['protocol'] ?? 'tcp'), ['tcp', 'udp'], true) ? $rule['protocol'] : 'tcp',
                'port' => $port,
                'priority' => max(1, min(1000, $priority)),
                'enabled' => $enabled ?? true,
                'reason' => mb_substr($reason, 0, 120),
                'source_ip' => filter_var(($rule['source_ip'] ?? null), FILTER_VALIDATE_IP) ? (string) $rule['source_ip'] : null,
                'source_asn' => is_string($rule['source_asn'] ?? null) ? mb_substr(trim((string) $rule['source_asn']), 0, 16) : null,
                'target' => [
                    'scope' => is_string($rule['target']['scope'] ?? null) ? $rule['target']['scope'] : 'global',
                    'service' => $service !== '' ? mb_substr($service, 0, 64) : null,
                ],
            ];
        }

        usort($sanitized, static fn (array $left, array $right): int => [$left['priority'], $left['id']] <=> [$right['priority'], $right['id']]);
        return $sanitized;
    }


    /**
     * @param array<int, array<string, mixed>> $rules
     * @return array<int, array<string, mixed>>
     */
    private function canonicalizeUnifiedRules(array $rules): array
    {
        foreach ($rules as &$rule) {
            $rule['id'] = trim((string) ($rule['id'] ?? ''));
            $rule['type'] = strtolower(trim((string) ($rule['type'] ?? '')));
            $rule['action'] = strtolower(trim((string) ($rule['action'] ?? '')));
            $rule['protocol'] = strtolower(trim((string) ($rule['protocol'] ?? 'tcp')));
            $rule['port'] = max(0, min(65535, (int) ($rule['port'] ?? 0)));
            $rule['priority'] = max(1, min(1000, (int) ($rule['priority'] ?? 100)));
            $rule['enabled'] = (bool) ($rule['enabled'] ?? true);
            $rule['reason'] = mb_substr(preg_replace('/\s+/', ' ', trim((string) ($rule['reason'] ?? ''))) ?? '', 0, 120);
            $sourceIp = trim((string) ($rule['source_ip'] ?? ''));
            if ($sourceIp !== '' && str_contains($sourceIp, '/')) {
                [$ip, $mask] = array_pad(explode('/', $sourceIp, 2), 2, null);
                $packed = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? ip2long($ip) : false;
                if ($packed !== false && is_numeric($mask)) {
                    $maskInt = max(0, min(32, (int) $mask));
                    $network = long2ip($packed & (-1 << (32 - $maskInt)));
                    $sourceIp = sprintf('%s/%d', $network, $maskInt);
                }
            }
            $rule['source_ip'] = $sourceIp !== '' ? $sourceIp : null;
            $rule['source_asn'] = isset($rule['source_asn']) ? mb_strtoupper(trim((string) $rule['source_asn'])) : null;
            $target = is_array($rule['target'] ?? null) ? $rule['target'] : [];
            $rule['target'] = [
                'scope' => strtolower(trim((string) ($target['scope'] ?? 'global'))),
                'service' => ($target['service'] ?? null) !== null ? strtolower(trim((string) $target['service'])) : null,
            ];
        }
        unset($rule);

        usort($rules, static fn (array $left, array $right): int => [$left['priority'], $left['id'], $left['port']] <=> [$right['priority'], $right['id'], $right['port']]);

        return array_values($rules);
    }

    private function cleanupLegacyDistributedSecurity(\App\Module\Core\Domain\Entity\Agent $agent): void
    {
        $jobs = $this->jobRepository->findBy(['status' => \App\Module\Core\Domain\Enum\JobStatus::Queued], ['createdAt' => 'DESC']);
        foreach ($jobs as $job) {
            $payload = $job->getPayload();
            $agentId = is_string($payload['agent_id'] ?? null) ? $payload['agent_id'] : null;
            if ($agentId !== $agent->getId()) {
                continue;
            }
            if (in_array($job->getType(), ['firewall.open_ports', 'firewall.close_ports', 'fail2ban.policy.apply'], true)) {
                $job->transitionTo(\App\Module\Core\Domain\Enum\JobStatus::Cancelled);
            }
        }
    }

    private function buildPolicyRevision(\App\Module\Core\Domain\Entity\Agent $agent, string $policyType, array $payload, User $admin): SecurityPolicyRevision
    {
        $version = $this->policyRevisionRepository->nextVersion($agent, $policyType);
        $revision = new SecurityPolicyRevision($agent, $policyType, $version, $payload, 'queued', $admin);
        $this->entityManager->persist($revision);

        $this->auditLogger->log($admin, 'security.policy.revision_created', [
            'agent_id' => $agent->getId(),
            'policy_type' => $policyType,
            'version' => $version,
        ]);

        return $revision;
    }

    /**
     * @param \App\Module\Core\Domain\Entity\Agent[] $agents
     * @return array<string, SecurityPolicyRevision>
     */
    private function indexPolicyRevisions(array $agents, string $policyType): array
    {
        $revisions = $this->policyRevisionRepository->findLatestByNodesAndType($agents, $policyType);
        $index = [];
        foreach ($revisions as $revision) {
            $nodeId = $revision->getNode()->getId();
            if (!array_key_exists($nodeId, $index)) {
                $index[$nodeId] = $revision;
            }
        }

        return $index;
    }

    private function parseFail2banPayload(Request $request): array
    {
        $enabled = $request->request->get('enabled') === '1';
        $bantime = trim((string) $request->request->get('bantime', '10m'));
        $findtime = trim((string) $request->request->get('findtime', '10m'));
        $maxretry = trim((string) $request->request->get('maxretry', '5'));
        $ignoreIps = $this->parseCsvList((string) $request->request->get('ignore_ips', '127.0.0.1/8'));
        $jails = $this->parseCsvList((string) $request->request->get('jails', 'sshd'));
        $advancedConfig = trim((string) $request->request->get('advanced_config', ''));
        $dryRun = $request->request->get('dry_run') === '1';

        return [
            'enabled' => $enabled,
            'bantime' => $bantime,
            'findtime' => $findtime,
            'maxretry' => is_numeric($maxretry) ? (int) $maxretry : 5,
            'ignore_ips' => $ignoreIps,
            'jails' => $jails,
            'advanced_config' => $advancedConfig,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * @return string[]
     */
    private function parseCsvList(string $raw): array
    {
        $values = [];
        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $values[] = $entry;
        }

        return array_values(array_unique($values));
    }

    private function parseEventFilters(Request $request): array
    {
        $from = $this->parseDateFilter($request->query->get('event_from'));
        $to = $this->parseDateFilter($request->query->get('event_to'));

        return [
            'from' => $from,
            'to' => $to,
            'ip' => $this->sanitizeFilterString($request->query->get('event_ip')),
            'rule' => $this->sanitizeFilterString($request->query->get('event_rule')),
            'source' => $this->sanitizeFilterString($request->query->get('event_source')),
        ];
    }

    private function parseDateFilter(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function sanitizeFilterString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }

    /**
     * @param SecurityEvent[] $events
     */
    private function summarizeEvents(array $events): array
    {
        $blocked = 0;
        $allowed = 0;
        foreach ($events as $event) {
            $count = $event->getCount() ?? 1;
            if ($event->getDirection() === 'blocked') {
                $blocked += $count;
            } elseif ($event->getDirection() === 'allowed') {
                $allowed += $count;
            }
        }

        return [
            'blocked' => $blocked,
            'allowed' => $allowed,
        ];
    }

    /**
     * @param \App\Module\Core\Domain\Entity\Agent[] $agents
     * @return array<string, \App\Module\Core\Domain\Entity\Job>
     */
    private function buildFirewallJobIndex(array $agents): array
    {
        if ($agents === []) {
            return [];
        }

        $agentIds = array_map(static fn ($agent): string => $agent->getId(), $agents);
        $jobs = array_merge(
            $this->jobRepository->findLatestByType('firewall.open_ports', max(50, count($agents) * 4)),
            $this->jobRepository->findLatestByType('firewall.close_ports', max(50, count($agents) * 4)),
        );

        usort($jobs, static fn (\App\Module\Core\Domain\Entity\Job $left, \App\Module\Core\Domain\Entity\Job $right): int => $right->getCreatedAt() <=> $left->getCreatedAt());

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
     * @param array<int, array<string, mixed>> $rules
     * @return array<int, array{port: int, protocol: string, status: string}>
     */
    private function normalizeRules(array $rules): array
    {
        $normalized = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $port = $rule['port'] ?? null;
            $protocol = is_string($rule['protocol'] ?? null) ? strtolower($rule['protocol']) : '';
            $status = is_string($rule['status'] ?? null) ? strtolower($rule['status']) : '';

            if (!is_int($port) && !is_numeric($port)) {
                continue;
            }

            $port = (int) $port;
            if ($port <= 0 || $port > 65535) {
                continue;
            }

            if (!in_array($protocol, ['tcp', 'udp'], true)) {
                continue;
            }

            if (!in_array($status, ['open', 'closed'], true)) {
                continue;
            }

            $normalized[] = [
                'port' => $port,
                'protocol' => $protocol,
                'status' => $status,
            ];
        }

        usort($normalized, function (array $left, array $right): int {
            $portCompare = $left['port'] <=> $right['port'];
            if ($portCompare !== 0) {
                return $portCompare;
            }
            return $left['protocol'] <=> $right['protocol'];
        });

        return $normalized;
    }

    private function parsePorts(string $raw): array
    {
        $ports = [];
        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '' || !ctype_digit($entry)) {
                continue;
            }

            $port = (int) $entry;
            if ($port <= 0 || $port > 65535) {
                continue;
            }

            $ports[] = $port;
        }

        $ports = array_values(array_unique($ports));
        sort($ports);
        return $ports;
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

    private function requireAdmin(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Forbidden.');
        }

        return $actor;
    }
}
