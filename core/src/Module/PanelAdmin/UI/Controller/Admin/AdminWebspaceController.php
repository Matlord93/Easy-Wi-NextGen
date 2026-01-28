<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\Webspace;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Ports\Infrastructure\Repository\PortPoolRepository;
use App\Repository\AgentRepository;
use App\Repository\UserRepository;
use App\Repository\WebspaceRepository;
use App\Module\Core\Application\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/webspaces')]
final class AdminWebspaceController
{
    private const DEFAULT_PHP_VERSION = 'php8.4';
    private const DEFAULT_WEB_PORTS = [80, 443];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly AgentRepository $agentRepository,
        private readonly WebspaceRepository $webspaceRepository,
        private readonly PortPoolRepository $portPoolRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_webspaces', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return $this->renderPage();
    }

    #[Route(path: '', name: 'admin_webspaces_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $customerId = $request->request->get('customer_id');
        $nodeId = (string) $request->request->get('node_id', '');
        $path = trim((string) $request->request->get('path', ''));
        $docroot = trim((string) $request->request->get('docroot', ''));
        $domain = trim((string) $request->request->get('domain', ''));
        $phpVersion = trim((string) $request->request->get('php_version', self::DEFAULT_PHP_VERSION));
        $quotaValue = $request->request->get('quota');
        $diskLimitValue = $request->request->get('disk_limit_bytes', 0);
        $ftpEnabled = $request->request->getBoolean('ftp_enabled');
        $sftpEnabled = $request->request->getBoolean('sftp_enabled');

        if ($customerId === null || $nodeId === '' || $path === '' || $domain === '' || $quotaValue === null) {
            return $this->renderPage('Please complete all required fields.');
        }

        if (!is_numeric($quotaValue) || !is_numeric($diskLimitValue)) {
            return $this->renderPage('Disk limit and quota must be numeric.');
        }

        $quota = (int) $quotaValue;
        $diskLimitBytes = (int) $diskLimitValue;
        if ($quota < 0 || $diskLimitBytes < 0) {
            return $this->renderPage('Disk limit and quota must be zero or positive.');
        }

        if ($docroot === '') {
            $docroot = rtrim($path, '/') . '/public';
        }

        $customer = $this->userRepository->find($customerId);
        if ($customer === null || $customer->getType() !== UserType::Customer) {
            return $this->renderPage('Customer not found.');
        }

        $node = $this->agentRepository->find($nodeId);
        if ($node === null) {
            return $this->renderPage('Node not found.');
        }

        $assignedPort = $this->assignPort($node);
        if ($assignedPort === null) {
            return $this->renderPage('No available ports in the pool for this node.');
        }

        $webspace = new Webspace(
            $customer,
            $node,
            $path,
            $docroot,
            $domain,
            true,
            $assignedPort,
            $phpVersion,
            $quota,
            Webspace::STATUS_ACTIVE,
            $diskLimitBytes,
            $ftpEnabled,
            $sftpEnabled,
        );
        $this->entityManager->persist($webspace);
        $this->entityManager->flush();

        $systemUsername = sprintf('ws%d', $webspace->getId());
        $webspace->setSystemUsername($systemUsername);
        $this->entityManager->persist($webspace);

        $domainEntity = new Domain($customer, $webspace, $domain);
        $this->entityManager->persist($domainEntity);
        $this->entityManager->flush();

        $jobPayload = [
            'agent_id' => $node->getId(),
            'webspace_id' => (string) $webspace->getId(),
            'web_root' => $webspace->getPath(),
            'docroot' => $webspace->getDocroot(),
            'domain' => $webspace->getDomain(),
            'ddos_protection_enabled' => $webspace->isDdosProtectionEnabled(),
            'assigned_port' => $webspace->getAssignedPort(),
            'owner_user' => $systemUsername,
            'owner_group' => $systemUsername,
            'php_version' => $webspace->getPhpVersion(),
            'php_fpm_pool_path' => sprintf('/etc/easywi/web/php-fpm/%s.conf', $systemUsername),
            'php_fpm_listen' => sprintf('/run/easywi/php-fpm/%s.sock', $systemUsername),
            'nginx_include_path' => sprintf('/etc/easywi/web/nginx/includes/%s.conf', $systemUsername),
            'pool_name' => $systemUsername,
        ];
        $job = new Job('webspace.create', $jobPayload);
        $this->entityManager->persist($job);

        $domainJobPayload = [
            'agent_id' => $node->getId(),
            'domain_id' => (string) $domainEntity->getId(),
            'domain' => $domainEntity->getName(),
            'web_root' => $webspace->getPath(),
            'source_dir' => $webspace->getDocroot(),
            'docroot' => $webspace->getDocroot(),
            'nginx_vhost_path' => sprintf('/etc/easywi/web/nginx/vhosts/%s.conf', $domainEntity->getName()),
            'php_fpm_listen' => sprintf('/run/easywi/php-fpm/%s.sock', $systemUsername),
            'logs_dir' => rtrim($webspace->getPath(), '/') . '/logs',
        ];
        $domainJob = new Job('domain.add', $domainJobPayload);
        $this->entityManager->persist($domainJob);

        $firewallJob = $this->queueWebspaceFirewall($webspace);

        $this->auditLogger->log($actor, 'webspace.created', [
            'webspace_id' => $webspace->getId(),
            'customer_id' => $customer->getId(),
            'node_id' => $node->getId(),
            'path' => $webspace->getPath(),
            'docroot' => $webspace->getDocroot(),
            'domain' => $webspace->getDomain(),
            'ddos_protection_enabled' => $webspace->isDdosProtectionEnabled(),
            'assigned_port' => $webspace->getAssignedPort(),
            'php_version' => $webspace->getPhpVersion(),
            'quota' => $webspace->getQuota(),
            'disk_limit_bytes' => $webspace->getDiskLimitBytes(),
            'ftp_enabled' => $webspace->isFtpEnabled(),
            'sftp_enabled' => $webspace->isSftpEnabled(),
            'job_id' => $job->getId(),
            'domain_job_id' => $domainJob->getId(),
            'firewall_job_id' => $firewallJob?->getId(),
        ]);
        $this->entityManager->flush();

        return $this->renderPage(null, 'Webspace created.');
    }

    #[Route(path: '/{id}/suspend', name: 'admin_webspaces_suspend', methods: ['POST'])]
    public function suspend(Request $request, string $id): Response
    {
        return $this->updateStatus($request, $id, Webspace::STATUS_SUSPENDED, 'webspace.suspended', 'Webspace suspended.');
    }

    #[Route(path: '/{id}/resume', name: 'admin_webspaces_resume', methods: ['POST'])]
    public function resume(Request $request, string $id): Response
    {
        return $this->updateStatus($request, $id, Webspace::STATUS_ACTIVE, 'webspace.resumed', 'Webspace resumed.');
    }

    #[Route(path: '/{id}/delete', name: 'admin_webspaces_delete', methods: ['POST'])]
    public function delete(Request $request, string $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $webspace = $this->webspaceRepository->find($id);
        if ($webspace === null) {
            return $this->renderPage('Webspace not found.');
        }

        $webspace->setStatus(Webspace::STATUS_DELETED);
        $webspace->setDeletedAt(new \DateTimeImmutable());
        $this->entityManager->persist($webspace);

        $this->auditLogger->log($actor, 'webspace.deleted', [
            'webspace_id' => $webspace->getId(),
            'customer_id' => $webspace->getCustomer()->getId(),
            'node_id' => $webspace->getNode()->getId(),
        ]);
        $this->entityManager->flush();

        return $this->renderPage(null, 'Webspace deleted.');
    }

    private function queueWebspaceFirewall(Webspace $webspace): ?Job
    {
        $ports = self::DEFAULT_WEB_PORTS;
        $assignedPort = $webspace->getAssignedPort();
        if ($assignedPort !== null) {
            $ports[] = $assignedPort;
        }

        $ports = array_values(array_unique(array_filter($ports, static fn (int $port): bool => $port > 0 && $port <= 65535)));
        if ($ports === []) {
            return null;
        }

        $firewallJob = new Job('firewall.open_ports', [
            'agent_id' => $webspace->getNode()->getId(),
            'webspace_id' => (string) $webspace->getId(),
            'ports' => implode(',', array_map('strval', $ports)),
        ]);
        $this->entityManager->persist($firewallJob);

        return $firewallJob;
    }

    private function updateStatus(Request $request, string $id, string $status, string $auditEvent, string $notice): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $webspace = $this->webspaceRepository->find($id);
        if ($webspace === null) {
            return $this->renderPage('Webspace not found.');
        }

        $previousStatus = $webspace->getStatus();
        $webspace->setStatus($status);
        $this->entityManager->persist($webspace);

        $this->auditLogger->log($actor, $auditEvent, [
            'webspace_id' => $webspace->getId(),
            'customer_id' => $webspace->getCustomer()->getId(),
            'node_id' => $webspace->getNode()->getId(),
            'previous_status' => $previousStatus,
            'status' => $status,
        ]);
        $this->entityManager->flush();

        return $this->renderPage(null, $notice);
    }

    private function renderPage(?string $error = null, ?string $notice = null): Response
    {
        $webspaces = $this->webspaceRepository->findBy([], ['createdAt' => 'DESC']);
        $customers = $this->userRepository->findCustomers();
        $nodes = $this->agentRepository->findBy([], ['name' => 'ASC']);

        return new Response($this->twig->render('admin/webspaces/index.html.twig', [
            'webspaces' => array_map(fn (Webspace $webspace) => $this->normalizeWebspace($webspace), $webspaces),
            'customers' => $customers,
            'nodes' => $nodes,
            'error' => $error,
            'notice' => $notice,
            'activeNav' => 'webspaces',
        ]));
    }

    private function normalizeWebspace(Webspace $webspace): array
    {
        return [
            'id' => $webspace->getId(),
            'customer' => [
                'id' => $webspace->getCustomer()->getId(),
                'email' => $webspace->getCustomer()->getEmail(),
            ],
            'node' => [
                'id' => $webspace->getNode()->getId(),
                'name' => $webspace->getNode()->getName(),
            ],
            'path' => $webspace->getPath(),
            'docroot' => $webspace->getDocroot(),
            'domain' => $webspace->getDomain(),
            'ddos_protection_enabled' => $webspace->isDdosProtectionEnabled(),
            'assigned_port' => $webspace->getAssignedPort(),
            'php_version' => $webspace->getPhpVersion(),
            'quota' => $webspace->getQuota(),
            'disk_limit_bytes' => $webspace->getDiskLimitBytes(),
            'ftp_enabled' => $webspace->isFtpEnabled(),
            'sftp_enabled' => $webspace->isSftpEnabled(),
            'status' => $webspace->getStatus(),
            'created_at' => $webspace->getCreatedAt(),
        ];
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->isAdmin();
    }

    private function assignPort(\App\Module\Core\Domain\Entity\Agent $node): ?int
    {
        $pools = $this->portPoolRepository->findBy(['node' => $node], ['startPort' => 'ASC']);
        if ($pools === []) {
            return null;
        }

        $assigned = $this->webspaceRepository->findAssignedPortsByNode($node);
        $assignedLookup = array_fill_keys($assigned, true);

        foreach ($pools as $pool) {
            for ($port = $pool->getStartPort(); $port <= $pool->getEndPort(); $port++) {
                if (!isset($assignedLookup[$port])) {
                    return $port;
                }
            }
        }

        return null;
    }
}
