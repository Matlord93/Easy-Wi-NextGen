<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Api;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\WebspacePathSanitizer;
use App\Module\Core\Domain\Entity\Certificate;
use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\MailDomain;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\Webspace;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\UI\Api\ResponseEnvelopeFactory;
use App\Module\Ports\Infrastructure\Repository\PortPoolRepository;
use App\Repository\AgentRepository;
use App\Repository\DomainRepository;
use App\Repository\JobRepository;
use App\Repository\MailDomainRepository;
use App\Repository\MailNodeRepository;
use App\Repository\UserRepository;
use App\Repository\WebspaceRepository;
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
        private readonly DomainRepository $domainRepository,
        private readonly JobRepository $jobRepository,
        private readonly MailNodeRepository $mailNodeRepository,
        private readonly MailDomainRepository $mailDomainRepository,
        private readonly PortPoolRepository $portPoolRepository,
        private readonly AuditLogger $auditLogger,
        private readonly WebspacePathSanitizer $pathSanitizer,
        private readonly ResponseEnvelopeFactory $responseEnvelopeFactory,
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
        if (!$this->isValidDomain($normalizedDomain)) {
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
            $phpVersion,
            $quota,
            true,
            $assignedPort,
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

        $this->ensureMailDomainBinding($domainEntity);

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
            'webspace_id' => (string) $webspace->getId(),
            'domain_id' => (string) $domainEntity->getId(),
            'domain' => $domainEntity->getName(),
            'target_path' => '',
            'runtime' => $webspace->getRuntime(),
            'web_root' => $webspace->getPath(),
            'docroot' => $webspace->getDocroot(),
            'nginx_vhost_path' => sprintf('/etc/easywi/web/nginx/vhosts/%s.conf', $domainEntity->getName()),
            'php_fpm_listen' => sprintf('/run/easywi/php-fpm/%s.sock', $systemUsername),
            'redirect_https' => '0',
            'redirect_www' => '0',
            'extra_directives' => '',
        ];
        $domainJob = new Job('webspace.domain.apply', $domainJobPayload);
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

        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = max(1, min(200, (int) $request->query->get('per_page', 50)));
        $pagination = $this->webspaceRepository->findPaginated($page, $perPage);
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
                'apply_status' => $webspace->getApplyStatus(),
                'apply_required' => $webspace->isApplyRequired(),
                'last_apply_error_code' => $webspace->getLastApplyErrorCode(),
                'last_apply_error_message' => $webspace->getLastApplyErrorMessage(),
                'last_applied_at' => $webspace->getLastAppliedAt()?->format(DATE_RFC3339),
                'created_at' => $webspace->getCreatedAt()->format(DATE_RFC3339),
            ];
        }, $pagination['items']);

        $response = new JsonResponse(['webspaces' => $payload, 'page' => $pagination['page'], 'per_page' => $pagination['per_page'], 'total' => $pagination['total']]);
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
                'apply_status' => $webspace->getApplyStatus(),
                'apply_required' => $webspace->isApplyRequired(),
                'last_apply_error_code' => $webspace->getLastApplyErrorCode(),
                'last_apply_error_message' => $webspace->getLastApplyErrorMessage(),
                'last_applied_at' => $webspace->getLastAppliedAt()?->format(DATE_RFC3339),
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

        $systemUsername = $webspace->getSystemUsername();
        $deleteJob = new Job('webspace.delete', [
            'agent_id' => $webspace->getNode()->getId(),
            'webspace_id' => (string) $webspace->getId(),
            'web_root' => $webspace->getPath(),
            'docroot' => $webspace->getDocroot(),
            'owner_user' => $systemUsername,
            'owner_group' => $systemUsername,
            'php_fpm_pool_path' => sprintf('/etc/easywi/web/php-fpm/%s.conf', $systemUsername),
            'nginx_include_path' => sprintf('/etc/easywi/web/nginx/includes/%s.conf', $systemUsername),
        ]);
        $this->entityManager->persist($deleteJob);

        $this->auditLogger->log($actor, 'webspace.deleted', [
            'webspace_id' => $webspace->getId(),
            'customer_id' => $webspace->getCustomer()->getId(),
            'node_id' => $webspace->getNode()->getId(),
            'delete_job_id' => $deleteJob->getId(),
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


    #[Route(path: '/api/v1/customer/webspaces', name: 'customer_webspaces_create_v1', methods: ['POST'])]
    public function createCustomerWebspace(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            return $this->responseEnvelopeFactory->error($request, 'Unauthorized.', 'unauthorized', 401);
        }

        try {
            $payload = $request->toArray();
        } catch (\JsonException) {
            return $this->responseEnvelopeFactory->error($request, 'Invalid JSON payload.', 'invalid_payload', 400);
        }

        $name = trim((string) ($payload['name'] ?? ''));
        $runtime = trim((string) ($payload['runtime'] ?? 'nginx'));
        $documentRoot = (string) ($payload['document_root'] ?? 'public');
        $nodeId = (string) ($payload['node_id'] ?? '');

        try {
            $documentRoot = $this->pathSanitizer->sanitizeRelativePath($documentRoot);
        } catch (\InvalidArgumentException $e) {
            return $this->responseEnvelopeFactory->error($request, 'Invalid document root.', (string) $e->getMessage(), 400);
        }

        if ($name === '' || !in_array($runtime, ['nginx', 'apache'], true)) {
            return $this->responseEnvelopeFactory->error($request, 'Missing required fields.', 'validation_failed', 400);
        }

        $node = $this->agentRepository->find($nodeId);
        if ($node === null || !$node->isActive()) {
            return $this->responseEnvelopeFactory->error($request, 'Node not found or inactive.', 'webspace_node_invalid', 404);
        }

        $domain = $this->normalizeDomain($name);
        [$path, ] = $this->buildWebspacePaths($domain);
        $docroot = rtrim($path, '/') . '/' . ($documentRoot === '' ? 'public' : $documentRoot);

        $webspace = new Webspace($actor, $node, $path, $docroot, $domain, self::DEFAULT_PHP_VERSION, 1024);
        $webspace->setRuntime($runtime);
        $webspace->setApplyRequired(true);
        $webspace->setApplyStatus('running');
        $this->entityManager->persist($webspace);
        $this->entityManager->flush();

        $active = $this->jobRepository->findActiveByTypeAndPayloadField('webspace.provision', 'webspace_id', (string) $webspace->getId());
        if ($active !== null) {
            return $this->responseEnvelopeFactory->error($request, 'Provision already running.', 'webspace_action_in_progress', 409, 10, ['job_id' => $active->getId()]);
        }

        $job = new Job('webspace.provision', [
            'agent_id' => $node->getId(),
            'webspace_id' => (string) $webspace->getId(),
            'runtime' => $runtime,
            'web_root' => $path,
            'docroot' => $docroot,
        ]);
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Provision queued.', 202, [
            'details' => ['webspace_id' => $webspace->getId()],
        ]);
    }

    #[Route(path: '/api/v1/customer/webspaces/{id}/domains', name: 'customer_webspaces_domain_add_v1', methods: ['POST'])]
    public function addDomain(Request $request, int $id): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        $webspace = $this->webspaceRepository->find($id);
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer || !$webspace instanceof Webspace || $webspace->getCustomer()->getId() !== $actor->getId()) {
            return $this->responseEnvelopeFactory->error($request, 'Webspace not found.', 'webspace_not_found', 404);
        }

        try {
            $payload = $request->toArray();
        } catch (\JsonException) {
            return $this->responseEnvelopeFactory->error($request, 'Invalid JSON payload.', 'invalid_payload', 400);
        }

        $fqdn = $this->normalizeDomain((string) ($payload['fqdn'] ?? ''));
        $type = (string) ($payload['type'] ?? 'domain');
        $targetPath = (string) ($payload['target_path'] ?? '');
        $redirectHttps = (bool) ($payload['redirect_https'] ?? false);
        $redirectWww = (bool) ($payload['redirect_www'] ?? false);
        $requestTls = (bool) ($payload['request_tls'] ?? false);
        $extraDirectives = trim((string) ($payload['extra_directives'] ?? ''));

        try {
            $targetPath = $this->pathSanitizer->sanitizeRelativePath($targetPath);
        } catch (\InvalidArgumentException $e) {
            return $this->responseEnvelopeFactory->error($request, 'Invalid target path.', (string) $e->getMessage(), 400);
        }

        if (!$this->isValidDomain($fqdn)) {
            return $this->responseEnvelopeFactory->error($request, 'Domain invalid.', 'invalid_domain_name', 400);
        }

        $active = $this->findActiveWebspaceActionJob((string) $webspace->getId());
        if ($active !== null) {
            if ($active->getType() === 'webspace.domain.apply' && (($active->getPayload()['action'] ?? 'add') === 'add') && (string) ($active->getPayload()['domain'] ?? '') === $fqdn) {
                return $this->responseEnvelopeFactory->success($request, $active->getId(), 'Domain apply already running.', 202, ['status' => 'running', 'error_code' => 'webspace_action_in_progress', 'retry_after' => 10]);
            }

            return $this->responseEnvelopeFactory->error($request, 'Another webspace action is already running.', 'webspace_action_in_progress', 409, 10, ['job_id' => $active->getId()]);
        }

        $domain = new Domain($actor, $webspace, $fqdn, 'pending');
        $domain->setType($type === 'subdomain' ? 'subdomain' : 'domain');
        $domain->setTargetPath($targetPath === '' ? null : $targetPath);
        $domain->setRedirectHttps($redirectHttps);
        $domain->setRedirectWww($redirectWww);
        $domain->setApplyStatus('pending');
        $this->entityManager->persist($domain);
        $this->entityManager->flush();

        $this->ensureMailDomainBinding($domain);

        $job = new Job('webspace.domain.apply', [
            'agent_id' => $webspace->getNode()->getId(),
            'webspace_id' => (string) $webspace->getId(),
            'domain_id' => (string) $domain->getId(),
            'domain' => $fqdn,
            'target_path' => $targetPath,
            'runtime' => $webspace->getRuntime(),
            'web_root' => $webspace->getPath(),
            'docroot' => $webspace->getDocroot(),
            'php_fpm_listen' => sprintf('/run/easywi/php-fpm/%s.sock', $webspace->getSystemUsername()),
            'nginx_vhost_path' => sprintf('/etc/easywi/web/nginx/vhosts/%s.conf', $fqdn),
            'redirect_https' => $redirectHttps ? '1' : '0',
            'redirect_www' => $redirectWww ? '1' : '0',
            'extra_directives' => $extraDirectives,
        ]);
        $this->entityManager->persist($job);
        if ($requestTls) {
            $certificate = new Certificate($domain);
            $certificate->setStatus('pending');
            $this->entityManager->persist($certificate);

            $tlsWebroot = $targetPath === ''
                ? $webspace->getDocroot()
                : rtrim($webspace->getPath(), '/') . '/' . ltrim($targetPath, '/');
            $tlsJob = new Job('domain.ssl.issue', [
                'agent_id' => $webspace->getNode()->getId(),
                'webspace_id' => (string) $webspace->getId(),
                'domain_id' => (string) $domain->getId(),
                'domain' => $fqdn,
                'web_root' => $tlsWebroot,
                'php_fpm_listen' => sprintf('/run/easywi/php-fpm/%s.sock', $webspace->getSystemUsername()),
                'nginx_vhost_path' => sprintf('/etc/easywi/web/nginx/vhosts/%s.conf', $fqdn),
                'runtime' => $webspace->getRuntime(),
            ]);
            $this->entityManager->persist($tlsJob);
        }
        $webspace->setApplyRequired(true);
        $webspace->setApplyStatus('running');
        $this->entityManager->flush();

        return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Domain apply queued.', 202);
    }

    #[Route(path: '/api/v1/customer/webspaces/{id}/domains', name: 'customer_webspaces_domain_list_v1', methods: ['GET'])]
    public function listDomains(Request $request, int $id): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        $webspace = $this->webspaceRepository->find($id);
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer || !$webspace instanceof Webspace || $webspace->getCustomer()->getId() !== $actor->getId()) {
            return $this->responseEnvelopeFactory->error($request, 'Webspace not found.', 'webspace_not_found', 404);
        }

        $domains = $this->domainRepository->findBy(['webspace' => $webspace], ['id' => 'ASC']);
        $rows = [];
        foreach ($domains as $domain) {
            $certificate = $this->entityManager->getRepository(Certificate::class)->findOneBy(['domain' => $domain], ['id' => 'DESC']);
            $rows[] = $this->domainApiPayload($domain, $certificate);
        }

        return $this->responseEnvelopeFactory->success($request, null, 'Domains loaded.', 200, ['domains' => $rows]);
    }

    #[Route(path: '/api/v1/customer/webspaces/{id}/domains/{domainId}', name: 'customer_webspaces_domain_patch_v1', methods: ['PATCH'])]
    public function patchDomain(Request $request, int $id, int $domainId): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        $webspace = $this->webspaceRepository->find($id);
        $domain = $this->domainRepository->find($domainId);
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer || !$webspace instanceof Webspace || !$domain instanceof Domain || $webspace->getCustomer()->getId() !== $actor->getId() || $domain->getWebspace()?->getId() !== $webspace->getId()) {
            return $this->responseEnvelopeFactory->error($request, 'Domain not found.', 'domain_not_found', 404);
        }

        $active = $this->findActiveWebspaceActionJob((string) $webspace->getId());
        if ($active !== null) {
            return $this->responseEnvelopeFactory->error($request, 'Another webspace action is already running.', 'webspace_action_in_progress', 409, 10, ['job_id' => $active->getId()]);
        }

        $payload = $request->toArray();
        $targetPath = array_key_exists('target_path', $payload) ? (string) $payload['target_path'] : (string) ($domain->getTargetPath() ?? '');
        $redirectHttps = array_key_exists('redirect_https', $payload) ? (bool) $payload['redirect_https'] : $domain->isRedirectHttps();
        $redirectWww = array_key_exists('redirect_www', $payload) ? (bool) $payload['redirect_www'] : $domain->isRedirectWww();
        $targetPath = $this->pathSanitizer->sanitizeRelativePath($targetPath);

        $domain->setTargetPath($targetPath === '' ? null : $targetPath);
        $domain->setRedirectHttps($redirectHttps);
        $domain->setRedirectWww($redirectWww);
        $domain->setApplyStatus('pending');

        $job = $this->createDomainApplyJob($webspace, $domain, 'add');
        $this->entityManager->persist($job);
        $webspace->setApplyRequired(true);
        $webspace->setApplyStatus('running');
        $this->entityManager->flush();

        return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Domain update queued.', 202);
    }

    #[Route(path: '/api/v1/customer/webspaces/{id}/domains/{domainId}', name: 'customer_webspaces_domain_remove_v1', methods: ['DELETE'])]
    public function removeDomain(Request $request, int $id, int $domainId): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        $webspace = $this->webspaceRepository->find($id);
        $domain = $this->domainRepository->find($domainId);

        if (!$actor instanceof User || $actor->getType() !== UserType::Customer || !$webspace instanceof Webspace || !$domain instanceof Domain || $webspace->getCustomer()->getId() !== $actor->getId() || $domain->getWebspace()?->getId() !== $webspace->getId()) {
            return $this->responseEnvelopeFactory->error($request, 'Domain not found.', 'domain_not_found', 404);
        }

        $active = $this->findActiveWebspaceActionJob((string) $webspace->getId());
        if ($active !== null) {
            if ($active->getType() === 'webspace.domain.apply' && (($active->getPayload()['action'] ?? '') === 'remove') && (string) ($active->getPayload()['domain_id'] ?? '') === (string) $domain->getId()) {
                return $this->responseEnvelopeFactory->success($request, $active->getId(), 'Domain removal already running.', 202, ['status' => 'running', 'error_code' => 'webspace_action_in_progress', 'retry_after' => 10]);
            }

            return $this->responseEnvelopeFactory->error($request, 'Another webspace action is already running.', 'webspace_action_in_progress', 409, 10, ['job_id' => $active->getId()]);
        }

        $job = new Job('webspace.domain.apply', [
            'agent_id' => $webspace->getNode()->getId(),
            'webspace_id' => (string) $webspace->getId(),
            'domain_id' => (string) $domain->getId(),
            'domain' => $domain->getName(),
            'action' => 'remove',
            'runtime' => $webspace->getRuntime(),
            'web_root' => $webspace->getPath(),
            'nginx_vhost_path' => sprintf('/etc/easywi/web/nginx/vhosts/%s.conf', $domain->getName()),
        ]);
        $this->entityManager->persist($job);
        $this->entityManager->remove($domain);
        $webspace->setApplyRequired(true);
        $webspace->setApplyStatus('running');
        $this->entityManager->flush();

        return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Domain removal queued.', 202);
    }

    #[Route(path: '/api/v1/customer/webspaces/{id}/domains/{domainId}/ssl', name: 'customer_webspaces_domain_ssl_status_v1', methods: ['GET'])]
    public function sslStatus(Request $request, int $id, int $domainId): JsonResponse
    {
        [$error, $webspace, $domain] = $this->resolveOwnedDomain($request, $id, $domainId);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        $certificate = $this->entityManager->getRepository(Certificate::class)->findOneBy(['domain' => $domain], ['id' => 'DESC']);
        return $this->responseEnvelopeFactory->success($request, null, 'SSL status loaded.', 200, [
            'ssl' => $this->certificatePayload($certificate),
        ]);
    }

    #[Route(path: '/api/v1/customer/webspaces/{id}/domains/{domainId}/ssl/issue', name: 'customer_webspaces_domain_ssl_issue_v1', methods: ['POST'])]
    public function sslIssue(Request $request, int $id, int $domainId): JsonResponse
    {
        [$error, $webspace, $domain] = $this->resolveOwnedDomain($request, $id, $domainId);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        $certificate = $this->entityManager->getRepository(Certificate::class)->findOneBy(['domain' => $domain], ['id' => 'DESC']) ?? new Certificate($domain);
        $certificate->setStatus('pending');
        $certificate->setLastError(null);
        $this->entityManager->persist($certificate);
        $job = new Job('domain.ssl.issue', $this->buildSslJobPayload($webspace, $domain));
        $this->entityManager->persist($job);
        $this->entityManager->flush();
        return $this->responseEnvelopeFactory->success($request, $job->getId(), 'SSL issue queued.', 202);
    }

    #[Route(path: '/api/v1/customer/webspaces/{id}/domains/{domainId}/ssl/disable', name: 'customer_webspaces_domain_ssl_disable_v1', methods: ['POST'])]
    public function sslDisable(Request $request, int $id, int $domainId): JsonResponse
    {
        [$error, $webspace, $domain] = $this->resolveOwnedDomain($request, $id, $domainId);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        $active = $this->findActiveWebspaceActionJob((string) $webspace->getId());
        if ($active !== null) {
            return $this->responseEnvelopeFactory->error($request, 'Another webspace action is already running.', 'webspace_action_in_progress', 409, 10, ['job_id' => $active->getId()]);
        }

        $domain->setRedirectHttps(false);
        $domain->setApplyStatus('pending');
        $job = new Job('webspace.domain.apply', array_merge(
            $this->createDomainApplyJob($webspace, $domain, 'add')->getPayload(),
            ['ssl_enabled' => '0']
        ));
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $this->responseEnvelopeFactory->success($request, $job->getId(), 'SSL disabled and apply queued.', 202, [
            'domain' => ['id' => $domain->getId(), 'redirect_https' => $domain->isRedirectHttps(), 'apply_status' => $domain->getApplyStatus()],
        ]);
    }

    #[Route(path: '/api/v1/customer/webspaces/{id}/domains/{domainId}/ssl/renew', name: 'customer_webspaces_domain_ssl_renew_v1', methods: ['POST'])]
    public function sslRenew(Request $request, int $id, int $domainId): JsonResponse
    {
        [$error, $webspace, $domain] = $this->resolveOwnedDomain($request, $id, $domainId);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        $certificate = $this->entityManager->getRepository(Certificate::class)->findOneBy(['domain' => $domain], ['id' => 'DESC']);
        if (!$certificate instanceof Certificate) {
            return $this->responseEnvelopeFactory->error($request, 'No certificate found for domain.', 'certificate_not_found', 404);
        }
        $certificate->setStatus('renewing');
        $this->entityManager->persist($certificate);
        $job = new Job('domain.ssl.renew', $this->buildSslJobPayload($webspace, $domain));
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $this->responseEnvelopeFactory->success($request, $job->getId(), 'SSL renew queued.', 202);
    }

    #[Route(path: '/api/v1/customer/webspaces/{id}/apply', name: 'customer_webspaces_apply_v1', methods: ['POST'])]
    public function apply(Request $request, int $id): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        $webspace = $this->webspaceRepository->find($id);
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer || !$webspace instanceof Webspace || $webspace->getCustomer()->getId() !== $actor->getId()) {
            return $this->responseEnvelopeFactory->error($request, 'Webspace not found.', 'webspace_not_found', 404);
        }

        $active = $this->findActiveWebspaceActionJob((string) $webspace->getId());
        if ($active !== null) {
            if ($active->getType() === 'webspace.apply') {
                return $this->responseEnvelopeFactory->success($request, $active->getId(), 'Apply already running.', 202, ['status' => 'running', 'error_code' => 'webspace_action_in_progress', 'retry_after' => 10]);
            }

            return $this->responseEnvelopeFactory->error($request, 'Another webspace action is already running.', 'webspace_action_in_progress', 409, 10, ['job_id' => $active->getId()]);
        }

        $job = new Job('webspace.apply', [
            'agent_id' => $webspace->getNode()->getId(),
            'webspace_id' => (string) $webspace->getId(),
            'runtime' => $webspace->getRuntime(),
            'web_root' => $webspace->getPath(),
            'docroot' => $webspace->getDocroot(),
        ]);
        $webspace->setApplyStatus('running');
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Apply queued.', 202);
    }


    private function findActiveWebspaceActionJob(string $webspaceId): ?Job
    {
        foreach (['webspace.apply', 'webspace.domain.apply', 'webspace.provision'] as $type) {
            $active = $this->jobRepository->findActiveByTypeAndPayloadField($type, 'webspace_id', $webspaceId);
            if ($active !== null) {
                return $active;
            }
        }

        return null;
    }

    private function ensureMailDomainBinding(Domain $domain): void
    {
        if ($this->mailDomainRepository->findOneByDomain($domain) !== null) {
            return;
        }

        $mailNode = $this->mailNodeRepository->findOneBy([], ['id' => 'ASC']);
        if ($mailNode === null) {
            return;
        }

        $this->entityManager->persist(new MailDomain($domain, $mailNode));
        $this->entityManager->flush();
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


    private function isValidDomain(string $domain): bool
    {
        if ($domain === '' || strlen($domain) > 253 || str_contains($domain, '..') || str_starts_with($domain, '.') || str_ends_with($domain, '.')) {
            return false;
        }

        $labels = explode('.', $domain);
        if (count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63) {
                return false;
            }
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label)) {
                return false;
            }
        }

        return true;
    }

    private function createDomainApplyJob(Webspace $webspace, Domain $domain, string $action): Job
    {
        return new Job('webspace.domain.apply', [
            'agent_id' => $webspace->getNode()->getId(),
            'webspace_id' => (string) $webspace->getId(),
            'domain_id' => (string) $domain->getId(),
            'domain' => $domain->getName(),
            'aliases' => implode(',', $domain->getServerAliases()),
            'action' => $action,
            'runtime' => $webspace->getRuntime(),
            'web_root' => $webspace->getPath(),
            'docroot' => $webspace->getDocroot(),
            'target_path' => (string) ($domain->getTargetPath() ?? ''),
            'php_fpm_listen' => sprintf('/run/easywi/php-fpm/%s.sock', $webspace->getSystemUsername()),
            'nginx_vhost_path' => sprintf('/etc/easywi/web/nginx/vhosts/%s.conf', $domain->getName()),
            'nginx_include_path' => sprintf('/etc/easywi/web/nginx/includes/%s.conf', $webspace->getSystemUsername()),
            'redirect_https' => $domain->isRedirectHttps() ? '1' : '0',
            'redirect_www' => $domain->isRedirectWww() ? '1' : '0',
        ]);
    }

    private function buildSslJobPayload(Webspace $webspace, Domain $domain): array
    {
        return [
            'agent_id' => $webspace->getNode()->getId(),
            'webspace_id' => (string) $webspace->getId(),
            'domain_id' => (string) $domain->getId(),
            'domain' => $domain->getName(),
            'web_root' => $webspace->getPath(),
            'docroot' => $webspace->getDocroot(),
            'php_fpm_listen' => sprintf('/run/easywi/php-fpm/%s.sock', $webspace->getSystemUsername()),
            'nginx_vhost_path' => sprintf('/etc/easywi/web/nginx/vhosts/%s.conf', $domain->getName()),
            'nginx_include_path' => sprintf('/etc/easywi/web/nginx/includes/%s.conf', $webspace->getSystemUsername()),
            'cert_dir' => sprintf('/etc/easywi/web/certs/%s', $domain->getName()),
            'redirect_https' => $domain->isRedirectHttps() ? '1' : '0',
            'runtime' => $webspace->getRuntime(),
        ];
    }

    private function domainApiPayload(Domain $domain, ?Certificate $certificate): array
    {
        return [
            'id' => $domain->getId(),
            'fqdn' => $domain->getName(),
            'type' => $domain->getType(),
            'target_path' => $domain->getTargetPath(),
            'status' => $domain->getStatus(),
            'apply_status' => $domain->getApplyStatus(),
            'ssl_enabled' => $certificate?->getStatus() === 'active',
            'certificate_status' => $certificate?->getStatus(),
            'certificate_expires_at' => null,
            'redirect_https' => $domain->isRedirectHttps(),
            'redirect_www' => $domain->isRedirectWww(),
            'last_apply_error_code' => $domain->getLastErrorCode(),
            'last_apply_error_message' => $domain->getLastErrorMessage(),
        ];
    }

    private function certificatePayload(?Certificate $certificate): array
    {
        if (!$certificate instanceof Certificate) {
            return ['status' => 'none'];
        }
        return [
            'status' => $certificate->getStatus(),
            'issuer' => 'acme',
            'fullchain_path' => null,
            'privkey_path' => null,
            'issued_at' => null,
            'expires_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
        ];
    }

    private function resolveOwnedDomain(Request $request, int $webspaceId, int $domainId): array
    {
        $actor = $request->attributes->get('current_user');
        $webspace = $this->webspaceRepository->find($webspaceId);
        $domain = $this->domainRepository->find($domainId);
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer || !$webspace instanceof Webspace || !$domain instanceof Domain || $webspace->getCustomer()->getId() !== $actor->getId() || $domain->getWebspace()?->getId() !== $webspace->getId()) {
            return [$this->responseEnvelopeFactory->error($request, 'Domain not found.', 'domain_not_found', 404), null, null];
        }
        return [null, $webspace, $domain];
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
