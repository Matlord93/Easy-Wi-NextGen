<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Api;

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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class WebspaceApiController
{
    private const DEFAULT_PHP_VERSION = 'php8.4';
    private const DEFAULT_WEB_PORTS = [80, 443];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly AgentRepository $agentRepository,
        private readonly WebspaceRepository $webspaceRepository,
        private readonly PortPoolRepository $portPoolRepository,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/api/admin/webspaces', name: 'admin_create_webspace', methods: ['POST'])]
    #[Route(path: '/api/v1/admin/webspaces', name: 'admin_create_webspace_v1', methods: ['POST'])]
    public function createWebspace(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new JsonResponse(['error' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $payload = $request->toArray();
        $customerId = $payload['customer_id'] ?? null;
        $nodeId = (string) ($payload['node_id'] ?? '');
        $domain = trim((string) ($payload['domain'] ?? ''));
        $phpVersion = trim((string) ($payload['php_version'] ?? self::DEFAULT_PHP_VERSION));
        $quotaValue = $payload['quota'] ?? null;
        $diskLimitValue = $payload['disk_limit_bytes'] ?? 0;
        $ftpEnabled = (bool) ($payload['ftp_enabled'] ?? false);
        $sftpEnabled = (bool) ($payload['sftp_enabled'] ?? false);

        if ($customerId === null || $nodeId === '' || $domain === '' || $phpVersion === '' || $quotaValue === null) {
            return new JsonResponse(['error' => 'Missing required fields.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (!is_numeric($quotaValue) || !is_numeric($diskLimitValue)) {
            return new JsonResponse(['error' => 'Quota and disk limit must be numeric.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $quota = (int) $quotaValue;
        if ($quota < 0) {
            return new JsonResponse(['error' => 'Quota must be zero or positive.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $diskLimitBytes = (int) $diskLimitValue;
        if ($diskLimitBytes < 0) {
            return new JsonResponse(['error' => 'Disk limit must be zero or positive.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $normalizedDomain = $this->normalizeDomain($domain);
        if ($normalizedDomain === '') {
            return new JsonResponse(['error' => 'Domain is invalid.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $domain = $normalizedDomain;

        $customer = $this->userRepository->find($customerId);
        if ($customer === null || $customer->getType() !== UserType::Customer) {
            return new JsonResponse(['error' => 'Customer not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $node = $this->agentRepository->find($nodeId);
        if ($node === null) {
            return new JsonResponse(['error' => 'Node not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $availablePhpVersions = $this->extractPhpVersions($node->getMetadata());
        if ($availablePhpVersions !== [] && !in_array($phpVersion, $availablePhpVersions, true)) {
            return new JsonResponse(['error' => 'Selected PHP version is not available on the node.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $assignedPort = $this->assignPort($node);
        if ($assignedPort === null) {
            return new JsonResponse(['error' => 'No available ports in the pool for this node.'], JsonResponse::HTTP_CONFLICT);
        }

        [$path, $docroot] = $this->buildWebspacePaths($normalizedDomain);

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
            'nginx_include_path' => sprintf('/etc/easywi/web/nginx/includes/%s.conf', $systemUsername),
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

        $response = new JsonResponse([
            'id' => $webspace->getId(),
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
            'status' => $webspace->getStatus(),
            'job_id' => $job->getId(),
            'domain_job_id' => $domainJob->getId(),
        ], JsonResponse::HTTP_CREATED);
        if ($request->attributes->get('_route') === 'admin_create_webspace') {
            $response->headers->set('Deprecation', 'true');
        }
        return $response;
    }

    #[Route(path: '/api/admin/webspaces', name: 'admin_list_webspaces', methods: ['GET'])]
    #[Route(path: '/api/v1/admin/webspaces', name: 'admin_list_webspaces_v1', methods: ['GET'])]
    public function listAdminWebspaces(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new JsonResponse(['error' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $webspaces = $this->webspaceRepository->findBy([], ['createdAt' => 'DESC']);
        $payload = array_map(function (Webspace $webspace): array {
            $node = $webspace->getNode();
            $customer = $webspace->getCustomer();

            return [
                'id' => $webspace->getId(),
                'customer' => [
                    'id' => $customer->getId(),
                    'email' => $customer->getEmail(),
                ],
                'node' => [
                    'id' => $node->getId(),
                    'name' => $node->getName(),
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
                'created_at' => $webspace->getCreatedAt()->format(DATE_RFC3339),
            ];
        }, $webspaces);

        $response = new JsonResponse(['webspaces' => $payload]);
        if ($request->attributes->get('_route') === 'admin_list_webspaces') {
            $response->headers->set('Deprecation', 'true');
        }
        return $response;
    }

    #[Route(path: '/api/webspaces', name: 'customer_webspaces', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/webspaces', name: 'customer_webspaces_v1', methods: ['GET'])]
    public function listWebspaces(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            return new JsonResponse(['error' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $webspaces = $this->webspaceRepository->findByCustomer($actor);
        $payload = [];

        foreach ($webspaces as $webspace) {
            $node = $webspace->getNode();
            $payload[] = [
                'id' => $webspace->getId(),
                'node' => [
                    'id' => $node->getId(),
                    'name' => $node->getName(),
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
            ];
        }

        $response = new JsonResponse(['webspaces' => $payload]);
        if ($request->attributes->get('_route') === 'customer_webspaces') {
            $response->headers->set('Deprecation', 'true');
        }
        return $response;
    }

    #[Route(path: '/api/admin/webspaces/{id}/suspend', name: 'admin_suspend_webspace', methods: ['POST'])]
    #[Route(path: '/api/v1/admin/webspaces/{id}/suspend', name: 'admin_suspend_webspace_v1', methods: ['POST'])]
    public function suspendWebspace(Request $request, string $id): JsonResponse
    {
        return $this->updateWebspaceStatus($request, $id, Webspace::STATUS_SUSPENDED, 'webspace.suspended');
    }

    #[Route(path: '/api/admin/webspaces/{id}/resume', name: 'admin_resume_webspace', methods: ['POST'])]
    #[Route(path: '/api/v1/admin/webspaces/{id}/resume', name: 'admin_resume_webspace_v1', methods: ['POST'])]
    public function resumeWebspace(Request $request, string $id): JsonResponse
    {
        return $this->updateWebspaceStatus($request, $id, Webspace::STATUS_ACTIVE, 'webspace.resumed');
    }

    #[Route(path: '/api/admin/webspaces/{id}', name: 'admin_delete_webspace', methods: ['DELETE'])]
    #[Route(path: '/api/v1/admin/webspaces/{id}', name: 'admin_delete_webspace_v1', methods: ['DELETE'])]
    public function deleteWebspace(Request $request, string $id): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new JsonResponse(['error' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $webspace = $this->webspaceRepository->find($id);
        if ($webspace === null) {
            return new JsonResponse(['error' => 'Webspace not found.'], JsonResponse::HTTP_NOT_FOUND);
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

        $response = new JsonResponse(['status' => 'deleted']);
        if ($request->attributes->get('_route') === 'admin_delete_webspace') {
            $response->headers->set('Deprecation', 'true');
        }
        return $response;
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

    private function updateWebspaceStatus(Request $request, string $id, string $status, string $auditEvent): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new JsonResponse(['error' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $webspace = $this->webspaceRepository->find($id);
        if ($webspace === null) {
            return new JsonResponse(['error' => 'Webspace not found.'], JsonResponse::HTTP_NOT_FOUND);
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

        $response = new JsonResponse(['status' => $status]);
        $routeName = (string) $request->attributes->get('_route');
        if (in_array($routeName, ['admin_suspend_webspace', 'admin_resume_webspace'], true)) {
            $response->headers->set('Deprecation', 'true');
        }
        return $response;
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

    /**
     * @param array<string, mixed>|null $metadata
     *
     * @return string[]
     */
    private function extractPhpVersions(?array $metadata): array
    {
        if (!is_array($metadata)) {
            return [];
        }

        $value = $metadata['php_versions'] ?? null;
        if (is_string($value)) {
            $value = array_map('trim', explode(',', $value));
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $value), static fn (string $item): bool => $item !== ''));
    }

    /**
     * @return array{string, string}
     */
    private function buildWebspacePaths(string $domain): array
    {
        $path = '/var/www/' . $domain;

        return [$path, $path . '/public'];
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('/[^a-z0-9.-]/', '', $domain);

        return $domain ?? '';
    }
}
