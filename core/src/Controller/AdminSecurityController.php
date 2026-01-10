<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\UserType;
use App\Repository\AgentRepository;
use App\Repository\DdosProviderCredentialRepository;
use App\Repository\FirewallStateRepository;
use App\Repository\JobRepository;
use App\Service\AuditLogger;
use App\Service\Ddos\DdosCredentialManager;
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
    public function updateFirewall(Request $request, int $id): Response
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

        $existingJobs = $this->buildFirewallJobIndex([$agent]);
        $latestJob = $existingJobs[$agent->getId()] ?? null;
        $hasPending = $latestJob !== null && in_array($latestJob->getStatus()->value, ['queued', 'running'], true);

        if ($toOpen !== []) {
            if (!$hasPending) {
                $job = new \App\Entity\Job('firewall.open_ports', [
                    'agent_id' => $agent->getId(),
                    'ports' => implode(',', array_map('strval', $toOpen)),
                ]);
                $this->entityManager->persist($job);
                $this->auditLogger->log($admin, 'firewall.open_ports_queued', [
                    'agent_id' => $agent->getId(),
                    'ports' => $toOpen,
                    'job_id' => $job->getId(),
                ]);
            }
        }

        if ($toClose !== []) {
            if (!$hasPending) {
                $job = new \App\Entity\Job('firewall.close_ports', [
                    'agent_id' => $agent->getId(),
                    'ports' => implode(',', array_map('strval', $toClose)),
                ]);
                $this->entityManager->persist($job);
                $this->auditLogger->log($admin, 'firewall.close_ports_queued', [
                    'agent_id' => $agent->getId(),
                    'ports' => $toClose,
                    'job_id' => $job->getId(),
                ]);
            }
        }

        $this->entityManager->flush();

        return new RedirectResponse(sprintf('/admin/security?firewall=%s', $agent->getId()));
    }

    private function renderPage(User $admin, Request $request, array $errors = [], int $status = Response::HTTP_OK): Response
    {
        $agents = $this->agentRepository->findBy([], ['updatedAt' => 'DESC']);
        $firewallJobs = $this->buildFirewallJobIndex($agents);
        $firewallNodes = array_map(function ($agent) use ($firewallJobs): array {
            $state = $this->firewallStateRepository->findOneBy(['node' => $agent]);
            $ports = $state?->getPorts() ?? [];
            $rules = $state?->getRules() ?? [];
            sort($ports);
            $rules = $this->normalizeRules($rules);
            $job = $firewallJobs[$agent->getId()] ?? null;

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
            ];
        }, $agents);

        $credentials = $this->credentialRepository->findBy(['customer' => $admin], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/security/index.html.twig', [
            'activeNav' => 'security',
            'errors' => $errors,
            'firewallNodes' => $firewallNodes,
            'credentials' => $credentials,
            'ddosUpdated' => $request->query->get('ddos') === 'updated',
            'firewallUpdated' => $request->query->get('firewall'),
        ]), $status);
    }

    /**
     * @param \App\Entity\Agent[] $agents
     * @return array<string, \App\Entity\Job>
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

        usort($jobs, static fn (\App\Entity\Job $left, \App\Entity\Job $right): int => $right->getCreatedAt() <=> $left->getCreatedAt());

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
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Forbidden.');
        }

        return $actor;
    }
}
