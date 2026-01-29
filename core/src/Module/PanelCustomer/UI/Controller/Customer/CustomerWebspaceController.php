<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\Webspace;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\Application\AuditLogger;
use App\Repository\DomainRepository;
use App\Repository\MailboxRepository;
use App\Repository\WebspaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/webspace')]
final class CustomerWebspaceController
{
    public function __construct(
        private readonly WebspaceRepository $webspaceRepository,
        private readonly DomainRepository $domainRepository,
        private readonly MailboxRepository $mailboxRepository,
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

        return new Response($this->twig->render('customer/webspace/manage.html.twig', [
            'activeNav' => 'webspaces',
            'domains' => $this->normalizeDomains($domains),
            'selectedDomain' => $this->normalizeDomain($selectedDomain),
            'webspace' => $webspace ? $this->normalizeWebspaceDetail($webspace) : null,
            'webspaces' => $this->normalizeWebspaces($webspaces),
        ]));
    }

    #[Route(path: '/manage/docroot', name: 'customer_webspace_docroot_update', methods: ['POST'])]
    public function updateDocroot(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $webspace = $this->findCustomerWebspace($customer, $request->request->get('webspace_id'));
        if ($webspace === null) {
            return new Response('Webspace not found.', Response::HTTP_NOT_FOUND);
        }

        $docroot = trim((string) $request->request->get('docroot', ''));
        if ($docroot === '') {
            return new Response('Document root is required.', Response::HTTP_BAD_REQUEST);
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
        $job = new Job('domain.ssl.issue', [
            'agent_id' => $domain->getWebspace()->getNode()->getId(),
            'domain_id' => $domain->getId(),
            'domain' => $domain->getName(),
            'web_root' => $domain->getWebspace()->getDocroot(),
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

        $job = new Job('roundcube.install', [
            'agent_id' => $webspace->getNode()->getId(),
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
                'webspace_id' => $domain->getWebspace()->getId(),
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

}
