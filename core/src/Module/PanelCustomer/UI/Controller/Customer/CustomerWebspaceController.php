<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\Webspace;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\DomainRepository;
use App\Repository\JobRepository;
use App\Repository\MailboxRepository;
use App\Repository\WebspaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;
use App\Module\Core\Attribute\RequiresModule;

#[Route(path: '/webspace')]
#[RequiresModule('web')]
final class CustomerWebspaceController
{
    public function __construct(
        private readonly WebspaceRepository $webspaceRepository,
        private readonly DomainRepository $domainRepository,
        private readonly MailboxRepository $mailboxRepository,
        private readonly JobRepository $jobRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_webspace', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $webspaces = $this->webspaceRepository->findByCustomer($customer);
        $domains = $this->domainRepository->findByCustomer($customer);
        $mailboxes = $this->mailboxRepository->findByCustomer($customer);

        $sslSummary = $this->buildSslSummary($domains);

        return new Response($this->twig->render('customer/webspace/index.html.twig', [
            'activeNav' => 'webspaces',
            'summary' => [
                'webspaces' => count($webspaces),
                'domains' => count($domains),
                'mailboxes' => count($mailboxes),
                'ssl_expiring' => $sslSummary['expiring'],
                'ssl_missing' => $sslSummary['missing'],
            ],
            'webspaces' => $this->normalizeWebspaces($webspaces),
        ]));
    }

    #[Route(path: '/manage', name: 'customer_webspace_manage', methods: ['GET'])]
    public function manage(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $domains = $this->domainRepository->findByCustomer($customer);
        $selectedDomainId = (int) $request->query->get('domain_id', 0);

        $selectedDomain = null;
        if ($selectedDomainId > 0) {
            $selectedDomain = $this->findCustomerDomain($domains, $selectedDomainId);
        }

        $webspace = $selectedDomain?->getWebspace();
        $webspaces = $this->webspaceRepository->findByCustomer($customer);

        $webspaceDomains = [];
        if ($webspace !== null) {
            $webspaceDomains = array_filter($domains, static fn (Domain $domain): bool => $domain->getWebspace()?->getId() === $webspace->getId());
        }

        return new Response($this->twig->render('customer/webspace/manage.html.twig', [
            'activeNav' => 'webspaces',
            'domains' => $this->normalizeDomains($domains),
            'selectedDomain' => $this->normalizeDomain($selectedDomain),
            'webspace' => $webspace ? $this->normalizeWebspaceDetail($webspace) : null,
            'webspaces' => $this->normalizeWebspaces($webspaces),
            'webspaceDomains' => $this->normalizeDomains($webspaceDomains),
        ]));
    }

    #[Route(path: '/manage/homepage', name: 'customer_webspace_homepage_update', methods: ['POST'])]
    public function updateHomepage(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $domainId = (int) $request->request->get('domain_id', 0);
        $domain = $domainId > 0 ? $this->domainRepository->find($domainId) : null;
        if ($domain === null || $domain->getCustomer()->getId() !== $customer->getId()) {
            return new Response('Domain not found.', Response::HTTP_NOT_FOUND);
        }

        $webspace = $domain->getWebspace();
        if ($webspace === null || $webspace->getCustomer()->getId() !== $customer->getId()) {
            return new Response('Webspace not found.', Response::HTTP_NOT_FOUND);
        }

        $previousHomepage = $webspace->getDomain();
        $webspace->setDomain($domain->getName());
        $this->entityManager->persist($webspace);

        $this->auditLogger->log($customer, 'webspace.homepage_updated', [
            'webspace_id' => $webspace->getId(),
            'previous_homepage' => $previousHomepage,
            'homepage' => $domain->getName(),
        ]);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/webspace/manage?domain_id=' . $domain->getId()]);
    }

    #[Route(path: '/manage/subdomains/add', name: 'customer_webspace_subdomain_add', methods: ['POST'])]
    public function addSubdomain(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $domain = $this->findCustomerDomainByRequest($customer, $request);
        if ($domain === null) {
            return new Response('Domain not found.', Response::HTTP_NOT_FOUND);
        }

        $input = (string) $request->request->get('subdomain', '');
        [$aliases, $errors] = $this->parseSubdomainInput($input, $domain);
        if ($aliases === []) {
            $errors[] = 'Subdomain is required.';
        }
        if ($errors !== []) {
            return new Response(implode(' ', $errors), Response::HTTP_BAD_REQUEST);
        }

        $existing = $domain->getServerAliases();
        $updated = array_values(array_unique(array_merge($existing, $aliases)));
        if ($updated === $existing) {
            return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/webspace/manage?domain_id=' . $domain->getId()]);
        }

        $domain->setServerAliases($updated);
        $domain->setStatus('pending');
        $this->entityManager->persist($domain);

        $job = $this->queueDomainUpdateJob($domain);
        $this->auditLogger->log($customer, 'domain.subdomain_added', [
            'domain_id' => $domain->getId(),
            'webspace_id' => $domain->getWebspace()?->getId(),
            'aliases' => $aliases,
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/webspace/manage?domain_id=' . $domain->getId()]);
    }

    #[Route(path: '/manage/subdomains/remove', name: 'customer_webspace_subdomain_remove', methods: ['POST'])]
    public function removeSubdomain(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $domain = $this->findCustomerDomainByRequest($customer, $request);
        if ($domain === null) {
            return new Response('Domain not found.', Response::HTTP_NOT_FOUND);
        }

        $aliasInput = (string) $request->request->get('alias', '');
        $alias = $this->normalizeAliasInput($aliasInput, $domain);
        if ($alias === null || $alias === strtolower($domain->getName())) {
            return new Response('Invalid subdomain.', Response::HTTP_BAD_REQUEST);
        }

        $aliases = $domain->getServerAliases();
        $updated = array_values(array_diff($aliases, [$alias]));
        if ($aliases === $updated) {
            return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/webspace/manage?domain_id=' . $domain->getId()]);
        }

        $domain->setServerAliases($updated);
        $domain->setStatus('pending');
        $this->entityManager->persist($domain);

        $job = $this->queueDomainUpdateJob($domain);
        $this->auditLogger->log($customer, 'domain.subdomain_removed', [
            'domain_id' => $domain->getId(),
            'webspace_id' => $domain->getWebspace()?->getId(),
            'alias' => $alias,
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/webspace/manage?domain_id=' . $domain->getId()]);
    }

    #[Route(path: '/manage/docroot', name: 'customer_webspace_docroot_update', methods: ['POST'])]
    public function updateDocroot(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $webspace = $this->findCustomerWebspace($customer, $request->request->get('webspace_id'));
        if ($webspace === null) {
            return new Response('Webspace not found.', Response::HTTP_NOT_FOUND);
        }

        $docrootInput = trim((string) $request->request->get('docroot', ''));
        $docroot = $this->normalizeDocroot($webspace, $docrootInput);
        if ($docroot === null) {
            return new Response('Document root must be inside the web root.', Response::HTTP_BAD_REQUEST);
        }

        $webspace->setDocroot($docroot);
        $this->entityManager->persist($webspace);
        $job = $this->queueWebspaceJob('webspace.update', $webspace, [
            'docroot' => $docroot,
        ]);

        $this->auditLogger->log($customer, 'webspace.docroot_updated', [
            'webspace_id' => $webspace->getId(),
            'docroot' => $docroot,
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/webspace/manage?domain_id=' . $this->primaryDomainId($webspace)]);
    }

    #[Route(path: '/manage/php', name: 'customer_webspace_php_update', methods: ['POST'])]
    public function updatePhpSettings(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $webspace = $this->findCustomerWebspace($customer, $request->request->get('webspace_id'));
        if ($webspace === null) {
            return new Response('Webspace not found.', Response::HTTP_NOT_FOUND);
        }

        $settingsText = (string) $request->request->get('php_settings', '');
        $settings = $this->parseSettingsText($settingsText);
        $webspace->setPhpSettings($settings);
        $this->entityManager->persist($webspace);

        $job = $this->queueWebspaceJob('webspace.update', $webspace, [
            'php_settings' => $settings,
        ]);

        $this->auditLogger->log($customer, 'webspace.php_settings_updated', [
            'webspace_id' => $webspace->getId(),
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/webspace/manage?domain_id=' . $this->primaryDomainId($webspace)]);
    }

    #[Route(path: '/manage/cron', name: 'customer_webspace_cron_update', methods: ['POST'])]
    public function updateCron(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $webspace = $this->findCustomerWebspace($customer, $request->request->get('webspace_id'));
        if ($webspace === null) {
            return new Response('Webspace not found.', Response::HTTP_NOT_FOUND);
        }

        $tasks = (string) $request->request->get('cron_tasks', '');
        $webspace->setCronTasks($tasks);
        $this->entityManager->persist($webspace);

        $job = $this->queueWebspaceJob('webspace.cron.update', $webspace, [
            'cron_tasks' => $tasks,
        ]);

        $this->auditLogger->log($customer, 'webspace.cron_updated', [
            'webspace_id' => $webspace->getId(),
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/webspace/manage?domain_id=' . $this->primaryDomainId($webspace)]);
    }

    #[Route(path: '/manage/git', name: 'customer_webspace_git_update', methods: ['POST'])]
    public function updateGit(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $webspace = $this->findCustomerWebspace($customer, $request->request->get('webspace_id'));
        if ($webspace === null) {
            return new Response('Webspace not found.', Response::HTTP_NOT_FOUND);
        }

        $repoUrl = trim((string) $request->request->get('git_repo_url', ''));
        $branch = trim((string) $request->request->get('git_branch', 'main'));

        $webspace->setGitRepoUrl($repoUrl === '' ? null : $repoUrl);
        $webspace->setGitBranch($branch === '' ? null : $branch);
        $this->entityManager->persist($webspace);

        $job = null;
        if ($repoUrl !== '') {
            $job = $this->queueWebspaceJob('webspace.git.deploy', $webspace, [
                'repo_url' => $repoUrl,
                'branch' => $branch,
            ]);
        }

        $this->auditLogger->log($customer, 'webspace.git_updated', [
            'webspace_id' => $webspace->getId(),
            'job_id' => $job?->getId(),
        ]);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/webspace/manage?domain_id=' . $this->primaryDomainId($webspace)]);
    }

    #[Route(path: '/manage/composer', name: 'customer_webspace_composer_install', methods: ['POST'])]
    public function runComposer(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $webspace = $this->findCustomerWebspace($customer, $request->request->get('webspace_id'));
        if ($webspace === null) {
            return new Response('Webspace not found.', Response::HTTP_NOT_FOUND);
        }

        $job = $this->queueWebspaceJob('webspace.composer.install', $webspace, []);
        $this->auditLogger->log($customer, 'webspace.composer_install', [
            'webspace_id' => $webspace->getId(),
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/webspace/manage?domain_id=' . $this->primaryDomainId($webspace)]);
    }

    #[Route(path: '/manage/backup', name: 'customer_webspace_backup', methods: ['POST'])]
    public function backup(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $webspace = $this->findCustomerWebspace($customer, $request->request->get('webspace_id'));
        if ($webspace === null) {
            return new Response('Webspace not found.', Response::HTTP_NOT_FOUND);
        }

        $label = trim((string) $request->request->get('backup_label', ''));
        $job = $this->queueWebspaceJob('webspace.backup', $webspace, [
            'label' => $label,
        ]);

        $this->auditLogger->log($customer, 'webspace.backup_requested', [
            'webspace_id' => $webspace->getId(),
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/webspace/manage?domain_id=' . $this->primaryDomainId($webspace)]);
    }

    #[Route(path: '/manage/restore', name: 'customer_webspace_restore', methods: ['POST'])]
    public function restore(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $webspace = $this->findCustomerWebspace($customer, $request->request->get('webspace_id'));
        if ($webspace === null) {
            return new Response('Webspace not found.', Response::HTTP_NOT_FOUND);
        }

        $backupPath = trim((string) $request->request->get('backup_path', ''));
        if ($backupPath === '') {
            return new Response('Backup path is required.', Response::HTTP_BAD_REQUEST);
        }

        $job = $this->queueWebspaceJob('webspace.restore', $webspace, [
            'backup_path' => $backupPath,
        ]);
        $this->auditLogger->log($customer, 'webspace.restore_requested', [
            'webspace_id' => $webspace->getId(),
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/webspace/manage?domain_id=' . $this->primaryDomainId($webspace)]);
    }

    #[Route(path: '/manage/ssl', name: 'customer_webspace_ssl_issue', methods: ['POST'])]
    public function issueSsl(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $domainId = (int) $request->request->get('domain_id', 0);
        $domain = $domainId > 0 ? $this->domainRepository->find($domainId) : null;
        if ($domain === null || $domain->getCustomer()->getId() !== $customer->getId()) {
            return new Response('Domain not found.', Response::HTTP_NOT_FOUND);
        }

        $aliases = trim((string) $request->request->get('server_aliases', ''));
        $email = trim((string) $request->request->get('email', ''));
        $webspace = $domain->getWebspace();
        if ($webspace === null) {
            return new Response('Domain has no webspace.', Response::HTTP_CONFLICT);
        }

        $job = new Job('domain.ssl.issue', [
            'agent_id' => $webspace->getNode()->getId(),
            'domain_id' => $domain->getId(),
            'domain' => $domain->getName(),
            'web_root' => $webspace->getDocroot(),
            'server_aliases' => $aliases,
            'email' => $email,
        ]);
        $this->entityManager->persist($job);

        $this->auditLogger->log($customer, 'domain.ssl_issue_requested', [
            'domain_id' => $domain->getId(),
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/webspace/manage?domain_id=' . $domain->getId()]);
    }

    #[Route(path: '/manage/roundcube', name: 'customer_roundcube_install', methods: ['POST'])]
    public function installRoundcube(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $webspace = $this->findCustomerWebspace($customer, $request->request->get('webspace_id'));
        if ($webspace === null) {
            return new Response('Webspace not found.', Response::HTTP_NOT_FOUND);
        }

        $agentId = (string) $webspace->getNode()->getId();
        $activeJob = $this->jobRepository->findActiveByTypeAndPayloadField('roundcube.install', 'agent_id', $agentId);
        if ($activeJob !== null) {
            return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/webspace/manage?domain_id=' . $this->primaryDomainId($webspace)]);
        }

        $job = new Job('roundcube.install', [
            'agent_id' => $agentId,
        ]);
        $this->entityManager->persist($job);

        $this->auditLogger->log($customer, 'roundcube.install_requested', [
            'webspace_id' => $webspace->getId(),
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/webspace/manage?domain_id=' . $this->primaryDomainId($webspace)]);
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    /**
     * @param Domain[] $domains
     * @return array{expiring: int, missing: int}
     */
    private function buildSslSummary(array $domains): array
    {
        $now = new \DateTimeImmutable();
        $threshold = $now->modify('+30 days');
        $expiring = 0;
        $missing = 0;

        foreach ($domains as $domain) {
            $expiresAt = $domain->getSslExpiresAt();
            if ($expiresAt === null) {
                $missing++;
                continue;
            }

            if ($expiresAt <= $threshold) {
                $expiring++;
            }
        }

        return [
            'expiring' => $expiring,
            'missing' => $missing,
        ];
    }

    /**
     * @param Webspace[] $webspaces
     * @return array<int, array<string, mixed>>
     */
    private function normalizeWebspaces(array $webspaces): array
    {
        return array_map(static function (Webspace $webspace): array {
            return [
                'id' => $webspace->getId(),
                'domain' => $webspace->getDomain(),
                'node' => [
                    'id' => $webspace->getNode()->getId(),
                    'name' => $webspace->getNode()->getName(),
                ],
                'php_version' => $webspace->getPhpVersion(),
                'quota' => $webspace->getQuota(),
                'status' => $webspace->getStatus(),
                'apply_status' => $webspace->getApplyStatus(),
                'apply_required' => $webspace->isApplyRequired(),
                'last_apply_error_code' => $webspace->getLastApplyErrorCode(),
                'last_apply_error_message' => $webspace->getLastApplyErrorMessage(),
                'last_applied_at' => $webspace->getLastAppliedAt(),
                'updated_at' => $webspace->getUpdatedAt(),
                'ftp_enabled' => $webspace->isFtpEnabled(),
                'sftp_enabled' => $webspace->isSftpEnabled(),
                'disk_limit_bytes' => $webspace->getDiskLimitBytes(),
            ];
        }, $webspaces);
    }

    /**
     * @param Domain[] $domains
     */
    private function normalizeDomains(array $domains): array
    {
        return array_map(static function (Domain $domain): array {
            return [
                'id' => $domain->getId(),
                'name' => $domain->getName(),
                'status' => $domain->getStatus(),
                'server_aliases' => $domain->getServerAliases(),
                'webspace_id' => $domain->getWebspace()?->getId(),
            ];
        }, $domains);
    }

    private function normalizeDomain(?Domain $domain): ?array
    {
        if ($domain === null) {
            return null;
        }

        return [
            'id' => $domain->getId(),
            'name' => $domain->getName(),
            'status' => $domain->getStatus(),
            'ssl_expires_at' => $domain->getSslExpiresAt(),
            'webspace_id' => $domain->getWebspace()->getId(),
            'server_aliases' => $domain->getServerAliases(),
        ];
    }

    private function normalizeWebspaceDetail(Webspace $webspace): array
    {
        return [
            'id' => $webspace->getId(),
            'domain' => $webspace->getDomain(),
            'path' => $webspace->getPath(),
            'docroot' => $webspace->getDocroot(),
            'php_version' => $webspace->getPhpVersion(),
            'php_settings' => $webspace->getPhpSettings(),
            'cron_tasks' => $webspace->getCronTasks(),
            'git_repo_url' => $webspace->getGitRepoUrl(),
            'git_branch' => $webspace->getGitBranch(),
            'node' => [
                'id' => $webspace->getNode()->getId(),
                'name' => $webspace->getNode()->getName(),
            ],
        ];
    }

    /**
     * @param Domain[] $domains
     */
    private function findCustomerDomain(array $domains, int $id): ?Domain
    {
        foreach ($domains as $domain) {
            if ($domain->getId() === $id) {
                return $domain;
            }
        }
        return null;
    }

    private function findCustomerWebspace(User $customer, mixed $webspaceId): ?Webspace
    {
        $id = is_numeric($webspaceId) ? (int) $webspaceId : 0;
        if ($id <= 0) {
            return null;
        }
        $webspace = $this->webspaceRepository->find($id);
        if ($webspace === null || $webspace->getCustomer()->getId() !== $customer->getId()) {
            return null;
        }
        return $webspace;
    }

    private function queueWebspaceJob(string $type, Webspace $webspace, array $extra): Job
    {
        $payload = array_merge([
            'agent_id' => $webspace->getNode()->getId(),
            'webspace_id' => (string) $webspace->getId(),
            'web_root' => $webspace->getPath(),
            'docroot' => $webspace->getDocroot(),
            'logs_dir' => rtrim($webspace->getPath(), '/') . '/logs',
            'owner_user' => $webspace->getSystemUsername(),
            'owner_group' => $webspace->getSystemUsername(),
            'php_version' => $webspace->getPhpVersion(),
            'php_fpm_pool_path' => sprintf('/etc/easywi/web/php-fpm/%s.conf', $webspace->getSystemUsername()),
            'php_fpm_listen' => sprintf('/run/easywi/php-fpm/%s.sock', $webspace->getSystemUsername()),
            'nginx_include_path' => sprintf('/etc/easywi/web/nginx/includes/%s.conf', $webspace->getSystemUsername()),
            'pool_name' => $webspace->getSystemUsername(),
        ], $extra);

        $job = new Job($type, $payload);
        $this->entityManager->persist($job);

        return $job;
    }

    private function queueDomainUpdateJob(Domain $domain): Job
    {
        $webspace = $domain->getWebspace();
        if ($webspace === null) {
            throw new \LogicException('Domain has no associated webspace.');
        }

        $payload = [
            'agent_id' => $webspace->getNode()->getId(),
            'webspace_id' => (string) $webspace->getId(),
            'domain_id' => (string) $domain->getId(),
            'domain' => $domain->getName(),
            'target_path' => '',
            'runtime' => $webspace->getRuntime(),
            'web_root' => $webspace->getPath(),
            'docroot' => $webspace->getDocroot(),
            'nginx_vhost_path' => sprintf('/etc/easywi/web/nginx/vhosts/%s.conf', $domain->getName()),
            'php_fpm_listen' => sprintf('/run/easywi/php-fpm/%s.sock', $webspace->getSystemUsername()),
            'redirect_https' => '0',
            'redirect_www' => '0',
            'extra_directives' => '',
            'server_aliases' => implode(' ', $domain->getServerAliases()),
        ];

        $job = new Job('webspace.domain.apply', $payload);
        $this->entityManager->persist($job);

        return $job;
    }

    private function parseSettingsText(string $text): array
    {
        $settings = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $key = trim($key);
            $value = trim($value);
            if ($key !== '' && $value !== '') {
                $settings[$key] = $value;
            }
        }
        return $settings;
    }

    private function primaryDomainId(Webspace $webspace): int
    {
        $domains = $this->domainRepository->findBy(['webspace' => $webspace], ['createdAt' => 'ASC']);
        if ($domains === []) {
            return $webspace->getId();
        }
        return $domains[0]->getId() ?? $webspace->getId();
    }

    private function findCustomerDomainByRequest(User $customer, Request $request): ?Domain
    {
        $domainId = (int) $request->request->get('domain_id', 0);
        if ($domainId <= 0) {
            return null;
        }

        $domain = $this->domainRepository->find($domainId);
        if ($domain === null || $domain->getCustomer()->getId() !== $customer->getId()) {
            return null;
        }

        return $domain;
    }

    private function normalizeDocroot(Webspace $webspace, string $docroot): ?string
    {
        $docroot = trim($docroot);
        if ($docroot === '') {
            return null;
        }

        if (!str_starts_with($docroot, '/')) {
            $docroot = rtrim($webspace->getPath(), '/') . '/' . ltrim($docroot, '/');
        }

        $docroot = $this->normalizePath($docroot);
        $webRoot = rtrim($this->normalizePath($webspace->getPath()), '/');
        if (!str_starts_with($docroot . '/', $webRoot . '/')) {
            return null;
        }

        return $docroot;
    }

    private function normalizePath(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }

        $normalized = implode('/', $parts);
        if ($isAbsolute) {
            return '/' . $normalized;
        }

        return $normalized;
    }

    /**
     * @return array{0: string[], 1: string[]}
     */
    private function parseSubdomainInput(string $input, Domain $domain): array
    {
        $aliases = [];
        $errors = [];
        $rootDomain = strtolower($domain->getName());

        $candidates = preg_split('/[\s,;]+/', strtolower($input)) ?: [];
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }

            $alias = $this->normalizeAliasValue($candidate, $rootDomain);
            if ($alias === null) {
                $errors[] = sprintf('Invalid subdomain: %s.', $candidate);
                continue;
            }

            if ($alias === $rootDomain) {
                $errors[] = sprintf('Subdomain must not equal the root domain: %s.', $candidate);
                continue;
            }

            $aliases[] = $alias;
        }

        $aliases = array_values(array_unique($aliases));

        return [$aliases, $errors];
    }

    private function normalizeAliasInput(string $input, Domain $domain): ?string
    {
        $alias = trim(strtolower($input));
        if ($alias === '') {
            return null;
        }

        return $this->normalizeAliasValue($alias, strtolower($domain->getName()));
    }

    private function normalizeAliasValue(string $candidate, string $rootDomain): ?string
    {
        if (!str_contains($candidate, '.')) {
            $candidate = $candidate . '.' . $rootDomain;
        }

        if (!str_ends_with($candidate, '.' . $rootDomain) && $candidate !== $rootDomain) {
            return null;
        }

        if (!$this->isValidHostname($candidate)) {
            return null;
        }

        return $candidate;
    }

    private function isValidHostname(string $hostname): bool
    {
        if (strlen($hostname) > 253) {
            return false;
        }

        $labels = explode('.', $hostname);
        if (count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63) {
                return false;
            }
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                return false;
            }
        }

        return true;
    }

}
