<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Job;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\AgentRepository;
use App\Repository\DdosPolicyRepository;
use App\Repository\DdosStatusRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/ddos')]
final class AdminDdosController
{
    private const MODE_OPTIONS = ['rate-limit', 'syn-cookie', 'conn-limit', 'off'];
    private const PORT_OPTIONS = [22, 53, 80, 443, 27015, 27016, 25565];
    private const PROTOCOL_OPTIONS = ['tcp', 'udp'];

    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly DdosPolicyRepository $ddosPolicyRepository,
        private readonly DdosStatusRepository $ddosStatusRepository,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_ddos', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $admin = $this->requireAdmin($request);

        return $this->renderPage($admin, $request);
    }

    #[Route(path: '/apply', name: 'admin_ddos_apply', methods: ['POST'])]
    public function applyPolicy(Request $request): Response
    {
        $admin = $this->requireAdmin($request);

        $nodeIds = $this->normalizeNodeIds($request->request->all('nodes'));
        $selectedPorts = $this->parsePortsFromSelection($request->request->all('ports'));
        $customPorts = $this->parsePortsFromInput((string) $request->request->get('custom_ports', ''));
        $ports = $this->normalizePorts(array_merge($selectedPorts, $customPorts));
        $protocols = $this->normalizeProtocols($request->request->all('protocols'));
        $mode = trim((string) $request->request->get('mode', 'rate-limit'));

        $errors = [];
        if ($nodeIds === []) {
            $errors[] = 'Select at least one node.';
        }

        if ($ports === []) {
            $errors[] = 'Select at least one port.';
        }

        if ($protocols === []) {
            $errors[] = 'Select at least one protocol.';
        }

        if (!in_array($mode, self::MODE_OPTIONS, true)) {
            $errors[] = 'Invalid DDoS policy mode.';
        }

        $nodes = $this->agentRepository->findBy(['id' => $nodeIds]);
        if ($nodes === []) {
            $errors[] = 'Selected nodes could not be found.';
        }

        if ($errors !== []) {
            return $this->renderPage($admin, $request, $errors, Response::HTTP_BAD_REQUEST, [
                'nodes' => $nodeIds,
                'ports' => $selectedPorts,
                'custom_ports' => (string) $request->request->get('custom_ports', ''),
                'protocols' => $protocols,
                'mode' => $mode,
            ]);
        }

        $queuedJobs = [];
        foreach ($nodes as $node) {
            $payload = [
                'agent_id' => $node->getId(),
                'ports' => $ports,
                'protocols' => $protocols,
                'mode' => $mode,
                'requested_by' => [
                    'user_id' => $admin->getId(),
                    'email' => $admin->getEmail(),
                ],
                'requested_at' => (new \DateTimeImmutable())->format(DATE_RFC3339),
            ];

            $job = new Job('ddos.policy.apply', $payload);
            $this->entityManager->persist($job);
            $queuedJobs[] = $job;
        }

        foreach ($queuedJobs as $job) {
            $this->auditLogger->log($admin, 'ddos.policy.apply_queued', [
                'job_id' => $job->getId(),
                'mode' => $mode,
                'ports' => $ports,
                'protocols' => $protocols,
            ]);
        }

        $this->entityManager->flush();

        return new RedirectResponse('/admin/ddos?applied=1');
    }

    private function renderPage(
        User $admin,
        Request $request,
        array $errors = [],
        int $status = Response::HTTP_OK,
        array $formData = [],
    ): Response {
        $nodes = $this->agentRepository->findBy([], ['updatedAt' => 'DESC']);
        $policies = $this->ddosPolicyRepository->findByNodes($nodes);
        $statuses = $this->ddosStatusRepository->findByNodes($nodes);
        $policyByNode = [];
        foreach ($policies as $policy) {
            $policyByNode[$policy->getNode()->getId()] = $policy;
        }
        $statusByNode = [];
        foreach ($statuses as $statusItem) {
            $statusByNode[$statusItem->getNode()->getId()] = $statusItem;
        }

        $panelData = array_map(function ($node) use ($statusByNode, $policyByNode): array {
            $statusItem = $statusByNode[$node->getId()] ?? null;
            $policyItem = $policyByNode[$node->getId()] ?? null;
            $ports = $statusItem?->getPorts() ?? [];
            sort($ports);

            $protocols = $statusItem?->getProtocols() ?? [];
            sort($protocols);

            $policyPorts = $policyItem?->getPorts() ?? [];
            sort($policyPorts);

            $policyProtocols = $policyItem?->getProtocols() ?? [];
            sort($policyProtocols);

            return [
                'id' => $node->getId(),
                'name' => $node->getName() ?? 'Unnamed node',
                'attackActive' => $statusItem?->isAttackActive() ?? false,
                'pps' => $statusItem?->getPacketsPerSecond(),
                'connCount' => $statusItem?->getConnectionCount(),
                'ports' => $ports,
                'protocols' => $protocols,
                'mode' => $statusItem?->getMode(),
                'reportedAt' => $statusItem?->getReportedAt(),
                'updatedAt' => $statusItem?->getUpdatedAt(),
                'policyEnabled' => $policyItem?->isEnabled(),
                'policyMode' => $policyItem?->getMode(),
                'policyPorts' => $policyPorts,
                'policyProtocols' => $policyProtocols,
                'policyAppliedAt' => $policyItem?->getAppliedAt(),
                'policyUpdatedAt' => $policyItem?->getUpdatedAt(),
            ];
        }, $nodes);

        return new Response($this->twig->render('admin/ddos/index.html.twig', [
            'activeNav' => 'ddos',
            'errors' => $errors,
            'ddosApplied' => $request->query->get('applied') === '1',
            'nodes' => $nodes,
            'statusPanels' => $panelData,
            'portOptions' => self::PORT_OPTIONS,
            'protocolOptions' => self::PROTOCOL_OPTIONS,
            'modeOptions' => self::MODE_OPTIONS,
            'formData' => array_merge([
                'nodes' => [],
                'ports' => [],
                'custom_ports' => '',
                'protocols' => [],
                'mode' => 'rate-limit',
            ], $formData),
        ]), $status);
    }

    private function normalizeNodeIds(array $nodes): array
    {
        $normalized = [];
        foreach ($nodes as $nodeId) {
            if (!is_string($nodeId)) {
                continue;
            }

            $nodeId = trim($nodeId);
            if ($nodeId === '') {
                continue;
            }

            $normalized[] = $nodeId;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<int, mixed> $ports
     * @return int[]
     */
    private function parsePortsFromSelection(array $ports): array
    {
        $parsed = [];
        foreach ($ports as $port) {
            if (!is_numeric($port)) {
                continue;
            }

            $parsed[] = (int) $port;
        }

        return $this->normalizePorts($parsed);
    }

    /**
     * @return int[]
     */
    private function parsePortsFromInput(string $raw): array
    {
        $ports = [];
        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '' || !ctype_digit($entry)) {
                continue;
            }

            $ports[] = (int) $entry;
        }

        return $this->normalizePorts($ports);
    }

    /**
     * @param int[] $ports
     * @return int[]
     */
    private function normalizePorts(array $ports): array
    {
        $normalized = [];
        foreach ($ports as $port) {
            if (!is_int($port)) {
                continue;
            }

            if ($port <= 0 || $port > 65535) {
                continue;
            }

            $normalized[] = $port;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);
        return $normalized;
    }

    /**
     * @param array<int, mixed> $protocols
     * @return string[]
     */
    private function normalizeProtocols(array $protocols): array
    {
        $normalized = [];
        foreach ($protocols as $protocol) {
            if (!is_string($protocol)) {
                continue;
            }

            $value = strtolower(trim($protocol));
            if (!in_array($value, self::PROTOCOL_OPTIONS, true)) {
                continue;
            }

            $normalized[] = $value;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);
        return $normalized;
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
