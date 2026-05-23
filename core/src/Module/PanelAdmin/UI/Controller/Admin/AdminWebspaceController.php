<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Application\MailPasswordHasher;
use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\MailDomain;
use App\Module\Core\Domain\Entity\Mailbox;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\Webspace;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Ports\Infrastructure\Repository\PortPoolRepository;
use App\Repository\AgentRepository;
use App\Repository\DomainRepository;
use App\Repository\MailDomainRepository;
use App\Repository\MailboxRepository;
use App\Repository\MailNodeRepository;
use App\Repository\UserRepository;
use App\Repository\WebspaceNodeRepository;
use App\Repository\WebspaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(path: '/admin/webspaces')]
final class AdminWebspaceController
{
    private const DEFAULT_PHP_VERSION = 'php8.4';
    private const DEFAULT_WEB_PORTS = [80, 443];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly AgentRepository $agentRepository,
        private readonly MailNodeRepository $mailNodeRepository,
        private readonly MailDomainRepository $mailDomainRepository,
        private readonly MailboxRepository $mailboxRepository,
        private readonly DomainRepository $domainRepository,
        private readonly WebspaceRepository $webspaceRepository,
        private readonly WebspaceNodeRepository $webspaceNodeRepository,
        private readonly PortPoolRepository $portPoolRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly EncryptionService $encryptionService,
        private readonly MailPasswordHasher $mailPasswordHasher,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '', name: 'admin_webspaces', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        return $this->renderPage($request);
    }

    #[Route(path: '', name: 'admin_webspaces_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $payload = $request->request;
        $customerId = $payload->get('customer_id');
        $nodeId = (string) $payload->get('node_id', '');
        $domain = trim((string) $payload->get('domain', ''));
        $phpVersion = trim((string) $payload->get('php_version', self::DEFAULT_PHP_VERSION));
        $quotaValue = $payload->get('quota');
        $diskLimitValue = $payload->get('disk_limit_bytes', 0);
        $ftpEnabled = $payload->getBoolean('ftp_enabled');
        $sftpEnabled = $payload->getBoolean('sftp_enabled');

        if ($customerId === null || $nodeId === '' || $domain === '' || $quotaValue === null) {
            return $this->renderPage($request, 'Please complete all required fields.');
        }

        if (!is_numeric($quotaValue) || !is_numeric($diskLimitValue)) {
            return $this->renderPage($request, 'Disk limit and quota must be numeric.');
        }

        $quota = (int) $quotaValue;
        $diskLimitBytes = (int) $diskLimitValue;
        if ($quota < 0 || $diskLimitBytes < 0) {
            return $this->renderPage($request, 'Disk limit and quota must be zero or positive.');
        }

        $normalizedDomain = $this->normalizeDomain($domain);
        if ($normalizedDomain === '') {
            return $this->renderPage($request, 'Domain is invalid.');
        }
        $domain = $normalizedDomain;

        $customer = $this->userRepository->find($customerId);
        if ($customer === null || $customer->getType() !== UserType::Customer) {
            return $this->renderPage($request, 'Customer not found.');
        }

        $node = $this->agentRepository->find($nodeId);
        if ($node === null) {
            return $this->renderPage($request, 'Node not found.');
        }

        $availablePhpVersions = $this->extractPhpVersions($node->getMetadata());
        if ($availablePhpVersions !== [] && !in_array($phpVersion, $availablePhpVersions, true)) {
            return $this->renderPage($request, 'Selected PHP version is not available on the node.');
        }

        $assignedPort = $this->assignPort($node);
        if ($assignedPort === null) {
            return $this->renderPage($request, 'No available ports in the pool for this node.');
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

        $webspaceNode = $this->webspaceNodeRepository->findByAgent($node);
        $protectedVhostPaths = ($webspaceNode !== null && $webspaceNode->isPanelHost() && $webspaceNode->getPanelVhostPath() !== null)
            ? $webspaceNode->getPanelVhostPath()
            : '';

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
            'protected_vhost_paths' => $protectedVhostPaths,
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
            'protected_vhost_paths' => $protectedVhostPaths,
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

        return $this->renderPage($request, null, 'Webspace created.');
    }



    #[Route(path: '/{id}/domains', name: 'admin_webspaces_domain_add', methods: ['POST'])]
    public function addDomain(Request $request, string $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $webspace = $this->webspaceRepository->find($id);
        if (!$webspace instanceof Webspace) {
            return $this->renderPage($request, 'Webspace not found.');
        }

        $fqdn = $this->normalizeDomain((string) $request->request->get('fqdn', ''));
        if (!$this->isValidDomainName($fqdn)) {
            return $this->renderPage($request, 'Domain is invalid.');
        }
        if ($this->domainRepository->findOneBy(['name' => $fqdn]) instanceof Domain) {
            return $this->renderPage($request, 'Domain already exists.');
        }

        $domain = new Domain($webspace->getCustomer(), $webspace, $fqdn, 'pending');
        $domain->setType((string) $request->request->get('type', 'domain') === 'subdomain' ? 'subdomain' : 'domain');
        $domain->setTargetPath($this->normalizeOptionalPath((string) $request->request->get('target_path', '')));
        $domain->setRedirectHttps($request->request->getBoolean('redirect_https'));
        $domain->setRedirectWww($request->request->getBoolean('redirect_www'));
        $domain->setApplyStatus('pending');
        $domain->setServerAliases($this->parseAliases((string) $request->request->get('server_aliases', '')));
        $this->entityManager->persist($domain);
        $this->entityManager->flush();

        $this->ensureMailDomainBinding($domain);
        $this->entityManager->persist($this->queueDomainApplyJob($domain));
        if ($request->request->getBoolean('request_tls')) {
            $domain->setStatus('pending');
            $this->entityManager->persist($this->queueSslJob('domain.ssl.issue', $domain, [
                'email' => trim((string) $request->request->get('ssl_email', '')),
            ]));
        }

        $this->auditLogger->log($actor, 'webspace.domain.added', [
            'webspace_id' => $webspace->getId(),
            'domain_id' => $domain->getId(),
            'domain' => $domain->getName(),
        ]);
        $this->entityManager->flush();

        return $this->renderPage($request, null, 'Domain added and queued for deployment.');
    }

    #[Route(path: '/{id}/domains/{domainId}', name: 'admin_webspaces_domain_update', methods: ['POST'])]
    public function updateDomain(Request $request, string $id, int $domainId): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        [$webspace, $domain] = $this->resolveWebspaceDomain($id, $domainId);
        if (!$webspace instanceof Webspace || !$domain instanceof Domain) {
            return $this->renderPage($request, 'Domain not found.');
        }

        $domain->setTargetPath($this->normalizeOptionalPath((string) $request->request->get('target_path', '')));
        $domain->setRedirectHttps($request->request->getBoolean('redirect_https'));
        $domain->setRedirectWww($request->request->getBoolean('redirect_www'));
        $domain->setServerAliases($this->parseAliases((string) $request->request->get('server_aliases', '')));
        $domain->setApplyStatus('pending');
        $this->entityManager->persist($this->queueDomainApplyJob($domain));
        $this->auditLogger->log($actor, 'webspace.domain.updated', [
            'webspace_id' => $webspace->getId(),
            'domain_id' => $domain->getId(),
        ]);
        $this->entityManager->flush();

        return $this->renderPage($request, null, 'Domain settings saved and queued.');
    }

    #[Route(path: '/{id}/domains/{domainId}/delete', name: 'admin_webspaces_domain_delete', methods: ['POST'])]
    public function deleteDomain(Request $request, string $id, int $domainId): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        [$webspace, $domain] = $this->resolveWebspaceDomain($id, $domainId);
        if (!$webspace instanceof Webspace || !$domain instanceof Domain) {
            return $this->renderPage($request, 'Domain not found.');
        }

        $domain->setStatus('pending');
        $domain->setApplyStatus('pending');
        $this->entityManager->persist($this->queueDomainApplyJob($domain, ['action' => 'remove']));
        $this->auditLogger->log($actor, 'webspace.domain.removal_requested', [
            'webspace_id' => $webspace->getId(),
            'domain_id' => $domain->getId(),
            'domain' => $domain->getName(),
        ]);
        $this->entityManager->flush();

        return $this->renderPage($request, null, 'Domain removal queued.');
    }

    #[Route(path: '/{id}/domains/{domainId}/ssl/{action}', name: 'admin_webspaces_domain_ssl', requirements: ['action' => 'issue|renew|disable'], methods: ['POST'])]
    public function sslAction(Request $request, string $id, int $domainId, string $action): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        [$webspace, $domain] = $this->resolveWebspaceDomain($id, $domainId);
        if (!$webspace instanceof Webspace || !$domain instanceof Domain) {
            return $this->renderPage($request, 'Domain not found.');
        }

        if ($action === 'disable') {
            $domain->setSslExpiresAt(null);
            $domain->setRedirectHttps(false);
            $domain->setApplyStatus('pending');
            $job = $this->queueDomainApplyJob($domain, ['ssl_enabled' => '0']);
        } else {
            $domain->setStatus('pending');
            $job = $this->queueSslJob($action === 'renew' ? 'domain.ssl.renew' : 'domain.ssl.issue', $domain, [
                'email' => trim((string) $request->request->get('ssl_email', '')),
            ]);
        }

        $this->entityManager->persist($job);
        $this->auditLogger->log($actor, 'webspace.domain.ssl_'.$action, [
            'webspace_id' => $webspace->getId(),
            'domain_id' => $domain->getId(),
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();

        return $this->renderPage($request, null, 'SSL action queued.');
    }

    #[Route(path: '/{id}/mailboxes', name: 'admin_webspaces_mailbox_create', methods: ['POST'])]
    public function createMailbox(Request $request, string $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        [$webspace, $domain] = $this->resolveWebspaceDomain($id, (int) $request->request->get('domain_id', 0));
        if (!$webspace instanceof Webspace || !$domain instanceof Domain) {
            return $this->renderPage($request, 'Domain not found.');
        }

        $localPart = strtolower(trim((string) $request->request->get('local_part', '')));
        $password = (string) $request->request->get('password', '');
        $quota = max(1, (int) $request->request->get('quota', 1024));
        if (!preg_match('/^[a-z0-9._%+-]{1,64}$/', $localPart) || strlen($password) < 8) {
            return $this->renderPage($request, 'Mailbox local part or password is invalid.');
        }
        if ($this->mailboxRepository->findOneBy(['address' => $localPart.'@'.$domain->getName()]) instanceof Mailbox) {
            return $this->renderPage($request, 'Mailbox already exists.');
        }

        $mailbox = new Mailbox($domain, $localPart, $this->mailPasswordHasher->hash($password), $this->encryptionService->encrypt($password), $quota, true);
        $domain->setCapabilities($domain->hasWebspaceCapability(), true);
        $this->ensureMailDomainBinding($domain);
        $this->entityManager->persist($mailbox);
        $this->entityManager->flush();

        $mailboxJob = new Job('mailbox.create', [
            'agent_id' => $webspace->getNode()->getId(),
            'mailbox_id' => (string) $mailbox->getId(),
            'domain_id' => (string) $domain->getId(),
            'domain' => $domain->getName(),
            'local_part' => $mailbox->getLocalPart(),
            'address' => $mailbox->getAddress(),
            'password_hash' => $mailbox->getPasswordHash(),
            'quota_mb' => (string) $mailbox->getQuota(),
            'enabled' => 'true',
        ]);
        $this->entityManager->persist($mailboxJob);
        $this->auditLogger->log($actor, 'webspace.mailbox.created', [
            'webspace_id' => $webspace->getId(),
            'domain_id' => $domain->getId(),
            'mailbox_id' => $mailbox->getId(),
            'address' => $mailbox->getAddress(),
        ]);
        $this->entityManager->flush();

        return $this->renderPage($request, null, 'Mailbox created and queued.');
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
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $webspace = $this->webspaceRepository->find($id);
        if ($webspace === null) {
            return $this->renderPage($request, 'Webspace not found.');
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

        return $this->renderPage($request, null, 'Webspace deleted.');
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
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $webspace = $this->webspaceRepository->find($id);
        if ($webspace === null) {
            return $this->renderPage($request, 'Webspace not found.');
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

        return $this->renderPage($request, null, $notice);
    }

    private function renderPage(Request $request, ?string $error = null, ?string $notice = null): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = max(1, min(200, (int) $request->query->get('per_page', 50)));

        $pagination = $this->webspaceRepository->findPaginated($page, $perPage);
        $customers = $this->userRepository->findCustomers();
        $nodes = $this->agentRepository->findBy([], ['name' => 'ASC'], 200);
        $phpVersions = $this->collectPhpVersions($nodes);
        $panelHostNodeIds = $this->collectPanelHostNodeIds($nodes);

        return new Response($this->twig->render('admin/webspaces/index.html.twig', [
            'webspaces' => array_map(fn (Webspace $webspace) => $this->normalizeWebspace($webspace), $pagination['items']),
            'customers' => $customers,
            'nodes' => $nodes,
            'phpVersions' => $phpVersions === [] ? [self::DEFAULT_PHP_VERSION] : $phpVersions,
            'defaultPhpVersion' => self::DEFAULT_PHP_VERSION,
            'panelHostNodeIds' => $panelHostNodeIds,
            'error' => $error,
            'notice' => $notice,
            'page' => $pagination['page'],
            'per_page' => $pagination['per_page'],
            'total' => $pagination['total'],
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
            'apply_status' => $webspace->getApplyStatus(),
            'apply_required' => $webspace->isApplyRequired(),
            'last_apply_error_code' => $webspace->getLastApplyErrorCode(),
            'last_apply_error_message' => $webspace->getLastApplyErrorMessage(),
            'last_applied_at' => $webspace->getLastAppliedAt(),
            'created_at' => $webspace->getCreatedAt(),
            'domains' => array_map(fn (Domain $domain): array => $this->normalizeDomainRow($domain), $this->domainRepository->findBy(['webspace' => $webspace], ['createdAt' => 'ASC'])),
            'mailboxes' => array_map(static fn (Mailbox $mailbox): array => [
                'id' => $mailbox->getId(),
                'address' => $mailbox->getAddress(),
                'quota' => $mailbox->getQuota(),
                'enabled' => $mailbox->isEnabled(),
            ], $this->mailboxRepository->findBy(['customer' => $webspace->getCustomer()], ['createdAt' => 'DESC'], 25)),
        ];
    }

    private function normalizeDomainRow(Domain $domain): array
    {
        return [
            'id' => $domain->getId(),
            'name' => $domain->getName(),
            'type' => $domain->getType(),
            'status' => $domain->getStatus(),
            'apply_status' => $domain->getApplyStatus(),
            'target_path' => $domain->getTargetPath(),
            'redirect_https' => $domain->isRedirectHttps(),
            'redirect_www' => $domain->isRedirectWww(),
            'server_aliases' => implode(', ', $domain->getServerAliases()),
            'ssl_expires_at' => $domain->getSslExpiresAt(),
            'mail_enabled' => $domain->hasMailCapability(),
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
     * @param array<int, \App\Module\Core\Domain\Entity\Agent> $nodes
     *
     * @return string[]
     */
    private function collectPhpVersions(array $nodes): array
    {
        $versions = [];
        foreach ($nodes as $node) {
            $versions = array_merge($versions, $this->extractPhpVersions($node->getMetadata()));
        }

        $versions = array_values(array_unique($versions));
        sort($versions);

        return $versions;
    }

    /**
     * @return array{string, string}
     */
    private function buildWebspacePaths(string $domain): array
    {
        $path = '/var/www/' . $domain;

        return [$path, $path . '/public'];
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

    /**
     * @param array<int, \App\Module\Core\Domain\Entity\Agent> $nodes
     * @return array<string, true>
     */
    private function collectPanelHostNodeIds(array $nodes): array
    {
        $panelHostIds = [];
        foreach ($nodes as $node) {
            $webspaceNode = $this->webspaceNodeRepository->findByAgent($node);
            if ($webspaceNode !== null && $webspaceNode->isPanelHost()) {
                $panelHostIds[$node->getId()] = true;
            }
        }

        return $panelHostIds;
    }


    /**
     * @return array{0: Webspace|null, 1: Domain|null}
     */
    private function resolveWebspaceDomain(string $webspaceId, int $domainId): array
    {
        $webspace = $this->webspaceRepository->find($webspaceId);
        $domain = $domainId > 0 ? $this->domainRepository->find($domainId) : null;
        if (!$webspace instanceof Webspace || !$domain instanceof Domain || $domain->getWebspace()?->getId() !== $webspace->getId()) {
            return [null, null];
        }

        return [$webspace, $domain];
    }

    private function queueDomainApplyJob(Domain $domain, array $extra = []): Job
    {
        $webspace = $domain->getWebspace();
        if (!$webspace instanceof Webspace) {
            throw new \LogicException('Domain has no webspace.');
        }

        $payload = array_merge([
            'agent_id' => $webspace->getNode()->getId(),
            'webspace_id' => (string) $webspace->getId(),
            'domain_id' => (string) $domain->getId(),
            'domain' => $domain->getName(),
            'target_path' => (string) ($domain->getTargetPath() ?? ''),
            'runtime' => $webspace->getRuntime(),
            'web_root' => $webspace->getPath(),
            'docroot' => $webspace->getDocroot(),
            'nginx_vhost_path' => sprintf('/etc/easywi/web/nginx/vhosts/%s.conf', $domain->getName()),
            'nginx_include_path' => sprintf('/etc/easywi/web/nginx/includes/%s.conf', $webspace->getSystemUsername()),
            'php_fpm_listen' => sprintf('/run/easywi/php-fpm/%s.sock', $webspace->getSystemUsername()),
            'redirect_https' => $domain->isRedirectHttps() ? '1' : '0',
            'redirect_www' => $domain->isRedirectWww() ? '1' : '0',
            'ssl_enabled' => $domain->getSslExpiresAt() !== null ? '1' : '0',
            'server_aliases' => implode(' ', $domain->getServerAliases()),
            'extra_directives' => '',
        ], $extra);

        return new Job('webspace.domain.apply', $payload);
    }

    private function queueSslJob(string $type, Domain $domain, array $extra = []): Job
    {
        $webspace = $domain->getWebspace();
        if (!$webspace instanceof Webspace) {
            throw new \LogicException('Domain has no webspace.');
        }

        return new Job($type, array_merge([
            'agent_id' => $webspace->getNode()->getId(),
            'webspace_id' => (string) $webspace->getId(),
            'domain_id' => (string) $domain->getId(),
            'domain' => $domain->getName(),
            'server_aliases' => implode(' ', $domain->getServerAliases()),
            'web_root' => $webspace->getPath(),
            'docroot' => $webspace->getDocroot(),
            'php_fpm_listen' => sprintf('/run/easywi/php-fpm/%s.sock', $webspace->getSystemUsername()),
            'nginx_vhost_path' => sprintf('/etc/easywi/web/nginx/vhosts/%s.conf', $domain->getName()),
            'nginx_include_path' => sprintf('/etc/easywi/web/nginx/includes/%s.conf', $webspace->getSystemUsername()),
            'cert_dir' => sprintf('/etc/easywi/web/certs/%s', $domain->getName()),
            'runtime' => $webspace->getRuntime(),
        ], array_filter($extra, static fn ($value): bool => $value !== '')));
    }

    /** @return string[] */
    private function parseAliases(string $raw): array
    {
        $aliases = [];
        foreach (preg_split('/[\s,;]+/', strtolower($raw)) ?: [] as $alias) {
            $alias = $this->normalizeDomain($alias);
            if ($this->isValidDomainName($alias)) {
                $aliases[] = $alias;
            }
        }

        return array_values(array_unique($aliases));
    }

    private function normalizeOptionalPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        return trim(preg_replace('#/+#', '/', $path) ?? $path, '/');
    }

    private function isValidDomainName(string $domain): bool
    {
        if ($domain === '' || strlen($domain) > 253 || str_contains($domain, '..')) {
            return false;
        }
        $labels = explode('.', $domain);
        if (count($labels) < 2) {
            return false;
        }
        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63 || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('/[^a-z0-9.-]/', '', $domain);

        return $domain ?? '';
    }
}
