<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Application\MailAliasLoopGuard;
use App\Module\Core\Application\MailLimitEnforcer;
use App\Module\Core\Application\MailPasswordHasher;
use App\Module\Core\Application\MailDnsCheckService;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\MailAlias;
use App\Module\Core\Domain\Entity\Mailbox;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\DomainRepository;
use App\Repository\MailAliasRepository;
use App\Repository\MailPolicyRepository;
use App\Repository\MailboxRepository;
use App\Repository\MailDomainRepository;
use App\Repository\JobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use App\Module\Core\Attribute\RequiresModule;

#[Route(path: '/mail')]
#[RequiresModule('mail')]
final class CustomerMailController
{
    private const DEFAULT_IMAP_PORT = 993;
    private const DEFAULT_SMTP_PORT = 587;
    private const DEFAULT_IMAP_ENCRYPTION = 'ssl_tls';
    private const DEFAULT_SMTP_ENCRYPTION = 'starttls';

    private const MAX_ALIAS_DESTINATIONS = 20;

    private const ENCRYPTION_LABELS = [
        'ssl_tls' => 'SSL/TLS',
        'starttls' => 'STARTTLS',
        'none' => 'None',
    ];

    public function __construct(
        private readonly MailboxRepository $mailboxRepository,
        private readonly MailAliasRepository $mailAliasRepository,
        private readonly DomainRepository $domainRepository,
        private readonly MailDomainRepository $mailDomainRepository,
        private readonly JobRepository $jobRepository,
        private readonly MailPolicyRepository $mailPolicyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly EncryptionService $encryptionService,
        private readonly MailPasswordHasher $mailPasswordHasher,
        private readonly MailLimitEnforcer $mailLimitEnforcer,
        private readonly MailAliasLoopGuard $mailAliasLoopGuard,
        private readonly MailDnsCheckService $mailDnsCheckService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_mail', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $mailboxes = $this->mailboxRepository->findByCustomer($customer);
        $aliases = $this->mailAliasRepository->findByCustomer($customer);
        $domains = $this->domainRepository->findByCustomer($customer);

        return new Response($this->twig->render('customer/mail/index.html.twig', [
            'activeNav' => 'mail',
            'mailboxes' => $this->normalizeMailboxes($mailboxes),
            'domains' => $domains,
            'aliases' => $this->normalizeAliases($aliases),
            'client_settings' => $this->buildClientSettings($domains),
            'roundcube_bindings' => $this->buildRoundcubeBindings($domains),
            'csrf' => $this->csrfTokens($mailboxes, $aliases),
        ]));
    }

    #[Route(path: '', name: 'customer_mail_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        if (!$this->isCsrfValid($request, 'mailbox_create')) {
            return $this->renderWithErrors($customer, ['Invalid CSRF token.']);
        }

        $domainId = (int) $request->request->get('domain_id', 0);
        $localPart = strtolower(trim((string) $request->request->get('local_part', '')));
        $password = trim((string) $request->request->get('password', ''));
        $quotaValue = $request->request->get('quota', '');
        $enabled = $request->request->get('enabled') === '1';

        $errors = [];
        $domain = $domainId > 0 ? $this->domainRepository->find($domainId) : null;
        if ($domain === null || $domain->getCustomer()->getId() !== $customer->getId()) {
            $errors[] = 'Domain not found.';
        }
        if ($localPart === '' || !preg_match('/^[a-z0-9._+\\-]+$/i', $localPart) || str_contains($localPart, '@')) {
            $errors[] = 'Invalid mailbox name.';
        }
        if ($password === '' || mb_strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if (mb_strlen($password) > 72) {
            $errors[] = 'Password must not exceed 72 characters.';
        }
        if ($quotaValue === '' || !is_numeric($quotaValue)) {
            $errors[] = 'Quota must be numeric.';
        }
        $quota = is_numeric($quotaValue) ? (int) $quotaValue : -1;
        if ($quota < 0) {
            $errors[] = 'Quota must be zero or positive.';
        }

        if ($domain !== null) {
            $address = sprintf('%s@%s', $localPart, $domain->getName());
            if ($this->mailboxRepository->findOneByAddress($address) !== null) {
                $errors[] = 'Mailbox address already exists.';
            }
        }

        if ($errors !== []) {
            return $this->renderWithErrors($customer, $errors);
        }

        $passwordHash = $this->mailPasswordHasher->hash($password);
        $secretPayload = $this->encryptionService->encrypt($password);

        try {
            $mailbox = $this->entityManager->wrapInTransaction(function () use ($domain, $localPart, $passwordHash, $secretPayload, $quota, $enabled): Mailbox {
                $this->mailLimitEnforcer->lockDomainForMailboxCreate($domain);
                $mailDomain = $this->mailDomainRepository->findOneByDomain($domain);
                $limitError = $this->mailLimitEnforcer->canCreateMailbox($domain, $mailDomain, max($quota, 0));
                if ($limitError !== null) {
                    throw new \DomainException($limitError);
                }

                $mailbox = new Mailbox($domain, $localPart, $passwordHash, $secretPayload, $quota, $enabled);
                $this->entityManager->persist($mailbox);
                $this->entityManager->flush();

                return $mailbox;
            });
        } catch (\DomainException $exception) {
            return $this->renderWithErrors($customer, [$exception->getMessage()]);
        }

        if (!$mailbox instanceof Mailbox) {
            return $this->renderWithErrors($customer, ['Mailbox creation failed.']);
        }

        try {
            $job = $this->queueMailboxJob('mailbox.create', $mailbox, [
                'password_hash' => $passwordHash,
                'quota_mb' => (string) $mailbox->getQuota(),
                'enabled' => $mailbox->isEnabled() ? 'true' : 'false',
            ]);
        } catch (\DomainException $exception) {
            return $this->renderWithErrors($customer, [$exception->getMessage()]);
        }

        $this->auditLogger->log($customer, 'mailbox.created', [
            'mailbox_id' => $mailbox->getId(),
            'domain_id' => $domain->getId(),
            'address' => $mailbox->getAddress(),
            'quota' => $mailbox->getQuota(),
            'enabled' => $mailbox->isEnabled(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/mail']);
    }

    #[Route(path: '/{id}/quota', name: 'customer_mail_quota_update', methods: ['POST'])]
    public function updateQuota(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        if (!$this->isCsrfValid($request, 'mailbox_quota_' . $id)) {
            return $this->renderWithErrors($customer, ['Invalid CSRF token.']);
        }
        $mailbox = $this->loadMailbox($customer, $id);
        if ($mailbox === null) {
            return $this->renderWithErrors($customer, ['Mailbox not found.']);
        }

        $quotaValue = $request->request->get('quota', '');
        if ($quotaValue === '' || !is_numeric($quotaValue)) {
            return $this->renderWithErrors($customer, ['Quota must be numeric.']);
        }

        $quota = (int) $quotaValue;
        if ($quota < 0) {
            return $this->renderWithErrors($customer, ['Quota must be zero or positive.']);
        }

        if ($quota !== $mailbox->getQuota()) {
            $previousQuota = $mailbox->getQuota();
            $mailbox->setQuota($quota);
            try {
                $job = $this->queueMailboxJob('mailbox.quota.update', $mailbox, [
                    'quota_mb' => (string) $quota,
                ]);
            } catch (\DomainException $exception) {
                return $this->renderWithErrors($customer, [$exception->getMessage()]);
            }

            $this->auditLogger->log($customer, 'mailbox.quota_updated', [
                'mailbox_id' => $mailbox->getId(),
                'address' => $mailbox->getAddress(),
                'previous_quota' => $previousQuota,
                'quota' => $quota,
                'job_id' => $job->getId(),
            ]);
        }

        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/mail']);
    }

    #[Route(path: '/{id}/status', name: 'customer_mail_status_update', methods: ['POST'])]
    public function updateStatus(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        if (!$this->isCsrfValid($request, 'mailbox_status_' . $id)) {
            return $this->renderWithErrors($customer, ['Invalid CSRF token.']);
        }
        $mailbox = $this->loadMailbox($customer, $id);
        if ($mailbox === null) {
            return $this->renderWithErrors($customer, ['Mailbox not found.']);
        }

        $enabled = $request->request->get('enabled') === '1';
        if ($enabled !== $mailbox->isEnabled()) {
            $previousEnabled = $mailbox->isEnabled();
            $mailbox->setEnabled($enabled);
            $jobType = $enabled ? 'mailbox.enable' : 'mailbox.disable';
            try {
                $job = $this->queueMailboxJob($jobType, $mailbox, [
                    'enabled' => $enabled ? 'true' : 'false',
                ]);
            } catch (\DomainException $exception) {
                return $this->renderWithErrors($customer, [$exception->getMessage()]);
            }

            $this->auditLogger->log($customer, $enabled ? 'mailbox.enabled' : 'mailbox.disabled', [
                'mailbox_id' => $mailbox->getId(),
                'address' => $mailbox->getAddress(),
                'previous_enabled' => $previousEnabled,
                'enabled' => $enabled,
                'job_id' => $job->getId(),
            ]);
        }

        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/mail']);
    }

    #[Route(path: '/{id}/password', name: 'customer_mail_password_reset', methods: ['POST'])]
    public function resetPassword(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        if (!$this->isCsrfValid($request, 'mailbox_password_' . $id)) {
            return $this->renderWithErrors($customer, ['Invalid CSRF token.']);
        }
        $mailbox = $this->loadMailbox($customer, $id);
        if ($mailbox === null) {
            return $this->renderWithErrors($customer, ['Mailbox not found.']);
        }

        $password = trim((string) $request->request->get('password', ''));
        if ($password === '') {
            return $this->renderWithErrors($customer, ['Password is required.']);
        }
        if (mb_strlen($password) < 8) {
            return $this->renderWithErrors($customer, ['Password must be at least 8 characters.']);
        }
        if (mb_strlen($password) > 72) {
            return $this->renderWithErrors($customer, ['Password must not exceed 72 characters.']);
        }

        $passwordHash = $this->mailPasswordHasher->hash($password);
        $secretPayload = $this->encryptionService->encrypt($password);
        $mailbox->setPassword($passwordHash, $secretPayload);
        try {
            $job = $this->queueMailboxJob('mailbox.password.reset', $mailbox, [
                'password_hash' => $passwordHash,
            ]);
        } catch (\DomainException $exception) {
            return $this->renderWithErrors($customer, [$exception->getMessage()]);
        }

        $this->auditLogger->log($customer, 'mailbox.password_reset', [
            'mailbox_id' => $mailbox->getId(),
            'address' => $mailbox->getAddress(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => sprintf('/mail/mailboxes/%d?password_scheduled=1', $mailbox->getId())]);
    }

    #[Route(path: '/{id}/delete', name: 'customer_mail_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        if (!$this->isCsrfValid($request, 'mailbox_delete_' . $id)) {
            return $this->renderWithErrors($customer, ['Invalid CSRF token.']);
        }
        $mailbox = $this->loadMailbox($customer, $id);
        if ($mailbox === null) {
            return $this->renderWithErrors($customer, ['Mailbox not found.']);
        }

        $job = $this->queueMailboxJob('mailbox.delete', $mailbox, []);

        $this->auditLogger->log($customer, 'mailbox.deleted', [
            'mailbox_id' => $mailbox->getId(),
            'address' => $mailbox->getAddress(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->remove($mailbox);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/mail']);
    }

    #[Route(path: '/mailboxes/{id}', name: 'customer_mailbox_detail', methods: ['GET'])]
    public function mailboxDetail(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $mailbox = $this->loadMailbox($customer, $id);
        if ($mailbox === null) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $mailDomain = $this->mailDomainRepository->findOneByDomain($mailbox->getDomain());
        $mailNode = $mailDomain?->getNode();
        $clientHost = $mailNode?->getImapHost() ?: sprintf('mail.%s', $mailbox->getDomain()->getName());
        $smtpHost = $mailNode?->getSmtpHost() ?: $clientHost;
        $lastJob = $this->findLastMailboxProvisioningJob($mailbox->getAddress());

        $policy = $this->mailPolicyRepository->findOneByDomain($mailbox->getDomain());
        // DNS check is deferred: only run when the user explicitly requests it
        // via ?dns_check=1 to avoid blocking every page load with external lookups.
        $dnsCheck = $request->query->get('dns_check') === '1'
            ? $this->mailDnsCheckService->check($mailbox->getDomain()->getName(), $clientHost)
            : null;

        return new Response($this->twig->render('customer/mail/detail.html.twig', [
            'activeNav' => 'mail',
            'mailbox' => $mailbox,
            'mail_node_name' => $mailNode?->getName(),
            'webmail_url' => $mailNode?->getRoundcubeUrl(),
            'username' => $mailbox->getAddress(),
            'imap' => ['host' => $clientHost, 'port' => 993, 'encryption' => 'SSL'],
            'pop3' => ['host' => $clientHost, 'port' => 995, 'encryption' => 'SSL'],
            'smtp' => ['host' => $smtpHost, 'port' => 587, 'encryption' => 'STARTTLS'],
            'last_job' => $lastJob,
            'password_csrf' => $this->csrfTokenManager->getToken('mailbox_password_' . $mailbox->getId())->getValue(),
            'password_scheduled' => $request->query->get('password_scheduled') === '1',
            'quota_usage' => $this->buildQuotaUsage($mailDomain?->getDomain()?->getWebspace()?->getNode()?->getLastHeartbeatStats(), $mailbox->getAddress(), $mailbox->getQuota()),
            'dns_check' => $dnsCheck,
            'smtp_policy' => [
                'smtp_enabled' => $policy?->isSmtpEnabled() ?? true,
                'send_limit_hour' => $policy?->getMaxHourlyEmails(),
                'recipient_limit' => $policy?->getMaxRecipients(),
                'abuse_policy_enabled' => $policy?->isAbusePolicyEnabled() ?? false,
            ],
        ]));
    }


    #[Route(path: '/aliases', name: 'customer_mail_alias_create', methods: ['POST'])]
    public function createAlias(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        if (!$this->isCsrfValid($request, 'alias_create')) {
            return $this->renderWithErrors($customer, ['Invalid CSRF token.']);
        }

        $domainId = (int) $request->request->get('domain_id', 0);
        $localPart = strtolower(trim((string) $request->request->get('local_part', '')));
        $destinations = $this->parseAliasDestinations((string) $request->request->get('destinations', ''));
        $enabled = $request->request->get('enabled') === '1';

        $errors = [];
        $domain = $domainId > 0 ? $this->domainRepository->find($domainId) : null;
        if ($domain === null || $domain->getCustomer()->getId() !== $customer->getId()) {
            $errors[] = 'Domain not found.';
        }
        if ($localPart === '' || !preg_match('/^[a-z0-9._+\-]+$/i', $localPart) || str_contains($localPart, '@')) {
            $errors[] = 'Invalid alias name.';
        }
        if ($destinations === []) {
            $errors[] = 'At least one destination is required.';
        }
        if (count($destinations) > self::MAX_ALIAS_DESTINATIONS) {
            $errors[] = sprintf('A maximum of %d destinations is allowed.', self::MAX_ALIAS_DESTINATIONS);
        }

        if ($domain !== null) {
            $policyError = $this->validateAliasPolicy($domain, $destinations);
            if ($policyError !== null) {
                $errors[] = $policyError;
            }
            $address = sprintf('%s@%s', $localPart, $domain->getName());
            if ($this->mailAliasRepository->findOneByAddress($address) !== null) {
                $errors[] = 'Alias address already exists.';
            }

            $existingAliases = $this->mailAliasRepository->findByCustomer($customer);
            if ($this->mailAliasLoopGuard->wouldCreateLoop($address, $destinations, $existingAliases)) {
                $errors[] = 'Alias loop detected.';
            }
        }

        if ($errors !== []) {
            return $this->renderWithErrors($customer, $errors);
        }

        $alias = new MailAlias($domain, $localPart, $destinations, $enabled);
        $this->entityManager->persist($alias);

        try {
            $job = $this->queueAliasJob('mail.alias.create', $alias, [
                'destinations' => implode(', ', $alias->getDestinations()),
                'enabled' => $alias->isEnabled() ? 'true' : 'false',
            ]);
        } catch (\DomainException $exception) {
            return $this->renderWithErrors($customer, [$exception->getMessage()]);
        }

        $this->auditLogger->log($customer, 'mail.alias_created', [
            'alias_id' => $alias->getId(),
            'address' => $alias->getAddress(),
            'destinations' => $alias->getDestinations(),
            'enabled' => $alias->isEnabled(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/mail']);
    }


    #[Route(path: '/aliases/{id}/destinations', name: 'customer_mail_alias_destinations_update', methods: ['POST'])]
    public function updateAliasDestinations(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        if (!$this->isCsrfValid($request, 'alias_destinations_' . $id)) {
            return $this->renderWithErrors($customer, ['Invalid CSRF token.']);
        }
        $alias = $this->loadAlias($customer, $id);
        if ($alias === null) {
            return $this->renderWithErrors($customer, ['Alias not found.']);
        }

        $destinations = $this->parseAliasDestinations((string) $request->request->get('destinations', ''));
        if ($destinations === []) {
            return $this->renderWithErrors($customer, ['At least one destination is required.']);
        }
        if (count($destinations) > self::MAX_ALIAS_DESTINATIONS) {
            return $this->renderWithErrors($customer, [sprintf('A maximum of %d destinations is allowed.', self::MAX_ALIAS_DESTINATIONS)]);
        }

        $policyError = $this->validateAliasPolicy($alias->getDomain(), $destinations);
        if ($policyError !== null) {
            return $this->renderWithErrors($customer, [$policyError]);
        }

        $existingAliases = $this->mailAliasRepository->findByCustomer($customer);
        $filteredAliases = array_values(array_filter($existingAliases, static fn (MailAlias $existing): bool => $existing->getId() !== $alias->getId()));
        if ($this->mailAliasLoopGuard->wouldCreateLoop($alias->getAddress(), $destinations, $filteredAliases)) {
            return $this->renderWithErrors($customer, ['Alias loop detected.']);
        }

        $previousDestinations = $alias->getDestinations();
        $alias->setDestinations($destinations);

        try {
            $job = $this->queueAliasJob('mail.alias.update', $alias, [
                'destinations' => implode(', ', $alias->getDestinations()),
                'enabled' => $alias->isEnabled() ? 'true' : 'false',
            ]);
        } catch (\DomainException $exception) {
            return $this->renderWithErrors($customer, [$exception->getMessage()]);
        }

        $this->auditLogger->log($customer, 'mail.alias_updated', [
            'alias_id' => $alias->getId(),
            'address' => $alias->getAddress(),
            'previous_destinations' => $previousDestinations,
            'destinations' => $alias->getDestinations(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/mail']);
    }

    #[Route(path: '/aliases/{id}/status', name: 'customer_mail_alias_status_update', methods: ['POST'])]
    public function updateAliasStatus(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        if (!$this->isCsrfValid($request, 'alias_status_' . $id)) {
            return $this->renderWithErrors($customer, ['Invalid CSRF token.']);
        }
        $alias = $this->loadAlias($customer, $id);
        if ($alias === null) {
            return $this->renderWithErrors($customer, ['Alias not found.']);
        }

        $enabled = $request->request->get('enabled') === '1';
        if ($enabled !== $alias->isEnabled()) {
            $previousEnabled = $alias->isEnabled();
            $alias->setEnabled($enabled);
            $jobType = $enabled ? 'mail.alias.enable' : 'mail.alias.disable';
            try {
                $job = $this->queueAliasJob($jobType, $alias, [
                    'destinations' => implode(', ', $alias->getDestinations()),
                    'enabled' => $enabled ? 'true' : 'false',
                ]);
            } catch (\DomainException $exception) {
                return $this->renderWithErrors($customer, [$exception->getMessage()]);
            }

            $this->auditLogger->log($customer, $enabled ? 'mail.alias_enabled' : 'mail.alias_disabled', [
                'alias_id' => $alias->getId(),
                'address' => $alias->getAddress(),
                'previous_enabled' => $previousEnabled,
                'enabled' => $enabled,
                'job_id' => $job->getId(),
            ]);
        }

        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/mail']);
    }

    #[Route(path: '/aliases/{id}/delete', name: 'customer_mail_alias_delete', methods: ['POST'])]
    public function deleteAlias(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        if (!$this->isCsrfValid($request, 'alias_delete_' . $id)) {
            return $this->renderWithErrors($customer, ['Invalid CSRF token.']);
        }
        $alias = $this->loadAlias($customer, $id);
        if ($alias === null) {
            return $this->renderWithErrors($customer, ['Alias not found.']);
        }

        try {
            $job = $this->queueAliasJob('mail.alias.delete', $alias, []);
        } catch (\DomainException $exception) {
            return $this->renderWithErrors($customer, [$exception->getMessage()]);
        }

        $this->auditLogger->log($customer, 'mail.alias_deleted', [
            'alias_id' => $alias->getId(),
            'address' => $alias->getAddress(),
            'destinations' => $alias->getDestinations(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->remove($alias);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/mail']);
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    private function renderWithErrors(User $customer, array $errors = []): Response
    {
        $mailboxes = $this->mailboxRepository->findByCustomer($customer);
        $aliases = $this->mailAliasRepository->findByCustomer($customer);
        $domains = $this->domainRepository->findByCustomer($customer);

        return new Response($this->twig->render('customer/mail/index.html.twig', [
            'activeNav' => 'mail',
            'mailboxes' => $this->normalizeMailboxes($mailboxes),
            'domains' => $domains,
            'aliases' => $this->normalizeAliases($aliases),
            'client_settings' => $this->buildClientSettings($domains),
            'roundcube_bindings' => $this->buildRoundcubeBindings($domains),
            'csrf' => $this->csrfTokens($mailboxes, $aliases),
            'errors' => $errors,
        ]), Response::HTTP_BAD_REQUEST);
    }

    private function loadMailbox(User $customer, int $id): ?Mailbox
    {
        $mailbox = $this->mailboxRepository->find($id);
        if ($mailbox === null || $mailbox->getCustomer()->getId() !== $customer->getId()) {
            return null;
        }

        return $mailbox;
    }

    private function queueMailboxJob(string $type, Mailbox $mailbox, array $extraPayload): Job
    {
        $domain = $mailbox->getDomain();
        $mailDomain = $this->mailDomainRepository->findOneByDomain($domain);
        $agentId = $mailDomain?->getNode()->getId();
        if ($agentId === null) {
            $agentId = $domain->getWebspace()?->getNode()?->getId();
        }
        if ($agentId === null) {
            throw new \DomainException('No mail agent/node assigned to this domain.');
        }
        $payload = array_merge([
            'mailbox_id' => (string) ($mailbox->getId() ?? ''),
            'domain_id' => (string) $domain->getId(),
            'domain' => $domain->getName(),
            'local_part' => $mailbox->getLocalPart(),
            'address' => $mailbox->getAddress(),
            'customer_id' => (string) $mailbox->getCustomer()->getId(),
            'agent_id' => (string) $agentId,
            'mail_enabled' => 'true',
            'mail_backend' => 'local',
            'smtp_enabled' => $this->resolveSmtpEnabled($domain),
            'send_limit_hour' => (string) $this->resolveSendLimitHour($domain),
            'recipient_limit' => (string) $this->resolveRecipientLimit($domain),
            'abuse_policy_enabled' => $this->resolveAbusePolicyEnabled($domain),
        ], $extraPayload);

        $job = new Job($type, $payload);
        $this->entityManager->persist($job);

        return $job;
    }


    private function loadAlias(User $customer, int $id): ?MailAlias
    {
        $alias = $this->mailAliasRepository->find($id);
        if ($alias === null || $alias->getCustomer()->getId() !== $customer->getId()) {
            return null;
        }

        return $alias;
    }

    private function queueAliasJob(string $type, MailAlias $alias, array $extraPayload): Job
    {
        $domain = $alias->getDomain();
        $mailDomain = $this->mailDomainRepository->findOneByDomain($domain);
        $agentId = $mailDomain?->getNode()->getId();
        if ($agentId === null) {
            $agentId = $domain->getWebspace()?->getNode()?->getId();
        }
        if ($agentId === null) {
            throw new \DomainException('No mail agent/node assigned to this domain.');
        }
        $payload = array_merge([
            'mail_alias_id' => (string) ($alias->getId() ?? ''),
            'domain_id' => (string) $domain->getId(),
            'domain' => $domain->getName(),
            'local_part' => $alias->getLocalPart(),
            'address' => $alias->getAddress(),
            'customer_id' => (string) $alias->getCustomer()->getId(),
            'agent_id' => (string) $agentId,
            'mail_enabled' => 'true',
            'mail_backend' => 'local',
            'smtp_enabled' => $this->resolveSmtpEnabled($domain),
            'send_limit_hour' => (string) $this->resolveSendLimitHour($domain),
            'recipient_limit' => (string) $this->resolveRecipientLimit($domain),
            'abuse_policy_enabled' => $this->resolveAbusePolicyEnabled($domain),
        ], $extraPayload);

        $job = new Job($type, $payload);
        $this->entityManager->persist($job);

        return $job;
    }

    /**
     * @return string[]
     */
    private function parseAliasDestinations(string $input): array
    {
        $items = preg_split('/[\r\n,;]+/', $input) ?: [];
        $cleaned = [];

        foreach ($items as $item) {
            $candidate = strtolower(trim($item));
            if ($candidate === '' || !filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $cleaned[$candidate] = true;
        }

        return array_keys($cleaned);
    }

    /**
     * @param string[] $destinations
     */
    private function validateAliasPolicy(\App\Module\Core\Domain\Entity\Domain $domain, array $destinations): ?string
    {
        $policy = $this->mailPolicyRepository->findOneByDomain($domain);
        if ($policy !== null && !$policy->isAllowExternalForwarding()) {
            $domainSuffix = '@' . strtolower($domain->getName());
            foreach ($destinations as $destination) {
                if (!str_ends_with(strtolower($destination), $domainSuffix)) {
                    return 'External forwarding is disabled by mail policy for this domain.';
                }
            }
        }

        return null;
    }


    private function resolveSmtpEnabled(\App\Module\Core\Domain\Entity\Domain $domain): string { $p=$this->mailPolicyRepository->findOneByDomain($domain); return ($p?->isSmtpEnabled() ?? true) ? 'true':'false'; }
    private function resolveSendLimitHour(\App\Module\Core\Domain\Entity\Domain $domain): int { return $this->mailPolicyRepository->findOneByDomain($domain)?->getMaxHourlyEmails() ?? 0; }
    private function resolveRecipientLimit(\App\Module\Core\Domain\Entity\Domain $domain): int { return $this->mailPolicyRepository->findOneByDomain($domain)?->getMaxRecipients() ?? 0; }
    private function resolveAbusePolicyEnabled(\App\Module\Core\Domain\Entity\Domain $domain): string { return ($this->mailPolicyRepository->findOneByDomain($domain)?->isAbusePolicyEnabled() ?? false) ? 'true' : 'false'; }

    /**
     * @param MailAlias[] $aliases
     */
    private function normalizeAliases(array $aliases): array
    {
        return array_map(static function (MailAlias $alias): array {
            return [
                'id' => $alias->getId(),
                'address' => $alias->getAddress(),
                'destinations' => $alias->getDestinations(),
                'enabled' => $alias->isEnabled(),
                'updated_at' => $alias->getUpdatedAt(),
            ];
        }, $aliases);
    }

    /**
     * @param Mailbox[] $mailboxes
     * @param MailAlias[] $aliases
     */
    private function csrfTokens(array $mailboxes, array $aliases): array
    {
        $tokens = [
            'mailbox_create' => $this->csrfTokenManager->getToken('mailbox_create')->getValue(),
            'alias_create' => $this->csrfTokenManager->getToken('alias_create')->getValue(),
            'mailboxes' => [],
            'aliases' => [],
        ];

        foreach ($mailboxes as $mailbox) {
            $id = $mailbox->getId();
            if ($id === null) {
                continue;
            }
            $tokens['mailboxes'][$id] = [
                'quota' => $this->csrfTokenManager->getToken('mailbox_quota_' . $id)->getValue(),
                'status' => $this->csrfTokenManager->getToken('mailbox_status_' . $id)->getValue(),
                'password' => $this->csrfTokenManager->getToken('mailbox_password_' . $id)->getValue(),
                'delete' => $this->csrfTokenManager->getToken('mailbox_delete_' . $id)->getValue(),
            ];
        }

        foreach ($aliases as $alias) {
            $id = $alias->getId();
            if ($id === null) {
                continue;
            }
            $tokens['aliases'][$id] = [
                'destinations' => $this->csrfTokenManager->getToken('alias_destinations_' . $id)->getValue(),
                'status' => $this->csrfTokenManager->getToken('alias_status_' . $id)->getValue(),
                'delete' => $this->csrfTokenManager->getToken('alias_delete_' . $id)->getValue(),
            ];
        }

        return $tokens;
    }

    private function isCsrfValid(Request $request, string $tokenId): bool
    {
        $token = new CsrfToken($tokenId, (string) $request->request->get('_token', ''));

        return $this->csrfTokenManager->isTokenValid($token);
    }

    /**
     * @param Mailbox[] $mailboxes
     */
    private function normalizeMailboxes(array $mailboxes): array
    {
        return array_map(static function (Mailbox $mailbox): array {
            return [
                'id' => $mailbox->getId(),
                'address' => $mailbox->getAddress(),
                'domain' => $mailbox->getDomain()->getName(),
                'quota' => $mailbox->getQuota(),
                'enabled' => $mailbox->isEnabled(),
                'updated_at' => $mailbox->getUpdatedAt(),
            ];
        }, $mailboxes);
    }

    /**
     * @param \App\Module\Core\Domain\Entity\Domain[] $domains
     */
    private function buildClientSettings(array $domains): array
    {
        $settings = [];

        foreach ($domains as $domain) {
            $webspace = $domain->getWebspace();
            if ($webspace === null) {
                continue;
            }
            $node = $webspace->getNode();
            $metadata = $node->getMetadata();
            $metadata = is_array($metadata) ? $metadata : [];
            $mailDomain = $this->mailDomainRepository->findOneByDomain($domain);
            if ($mailDomain !== null) {
                $mailNode = $mailDomain->getNode();
                $settings[] = [
                    'domain' => $domain->getName(),
                    'imap' => [
                        'host' => $this->normalizeClientHost($mailNode->getImapHost(), sprintf('mail.%s', $domain->getName())),
                        'port' => $mailNode->getImapPort(),
                        'encryption' => self::ENCRYPTION_LABELS[self::DEFAULT_IMAP_ENCRYPTION],
                    ],
                    'smtp' => [
                        'host' => $this->normalizeClientHost($mailNode->getSmtpHost(), sprintf('mail.%s', $domain->getName())),
                        'port' => $mailNode->getSmtpPort(),
                        'encryption' => self::ENCRYPTION_LABELS[self::DEFAULT_SMTP_ENCRYPTION],
                    ],
                    'pop3' => [
                        'host' => $this->normalizeClientHost($mailNode->getImapHost(), sprintf('mail.%s', $domain->getName())),
                        'port' => 995,
                        'encryption' => 'SSL/TLS',
                    ],
                    'dns' => [
                        'spf' => sprintf('v=spf1 mx include:%s -all', $mailNode->getSmtpHost()),
                        'dkim' => sprintf('%s._domainkey.%s', $mailDomain->getDkimSelector(), $domain->getName()),
                        'dmarc' => sprintf('v=DMARC1; p=%s; adkim=s; aspf=s', $mailDomain->getDmarcPolicy()),
                    ],
                ];

                continue;
            }
            $defaultHost = sprintf('mail.%s', $domain->getName());
            $imapHost = $this->resolveMetadataValue($metadata, 'mail_imap_host', 'mail_host', $defaultHost);
            $smtpHost = $this->resolveMetadataValue($metadata, 'mail_smtp_host', 'mail_host', $defaultHost);

            $imapPort = $this->resolvePort($metadata['mail_imap_port'] ?? null, self::DEFAULT_IMAP_PORT);
            $smtpPort = $this->resolvePort($metadata['mail_smtp_port'] ?? null, self::DEFAULT_SMTP_PORT);

            $imapEncryption = $this->normalizeEncryption($metadata['mail_imap_encryption'] ?? null, self::DEFAULT_IMAP_ENCRYPTION);
            $smtpEncryption = $this->normalizeEncryption($metadata['mail_smtp_encryption'] ?? null, self::DEFAULT_SMTP_ENCRYPTION);

            $settings[] = [
                'domain' => $domain->getName(),
                'imap' => [
                    'host' => $this->normalizeClientHost($imapHost, $defaultHost),
                    'port' => $imapPort,
                    'encryption' => self::ENCRYPTION_LABELS[$imapEncryption] ?? $imapEncryption,
                ],
                'smtp' => [
                    'host' => $this->normalizeClientHost($smtpHost, $defaultHost),
                    'port' => $smtpPort,
                    'encryption' => self::ENCRYPTION_LABELS[$smtpEncryption] ?? $smtpEncryption,
                ],
                'pop3' => [
                    'host' => $this->normalizeClientHost($imapHost, $defaultHost),
                    'port' => 995,
                    'encryption' => 'SSL/TLS',
                ],
                'dns' => [
                    'spf' => sprintf('v=spf1 mx a:mail.%s -all', $domain->getName()),
                    'dkim' => sprintf('default._domainkey.%s', $domain->getName()),
                    'dmarc' => 'v=DMARC1; p=quarantine; adkim=s; aspf=s',
                ],
            ];
        }

        return $settings;
    }

    private function buildRoundcubeBindings(array $domains): array
    {
        $bindings = [];
        foreach ($domains as $domain) {
            $mailDomain = $this->mailDomainRepository->findOneByDomain($domain);
            $bindings[] = [
                'domain' => $domain->getName(),
                'url' => $mailDomain?->getNode()->getRoundcubeUrl() ?? '/roundcube',
            ];
        }

        return $bindings;
    }

    private function resolveMetadataValue(array $metadata, string $primaryKey, string $fallbackKey, string $default): string
    {
        $primary = $metadata[$primaryKey] ?? null;
        if (is_string($primary) && trim($primary) !== '') {
            return trim($primary);
        }

        $fallback = $metadata[$fallbackKey] ?? null;
        if (is_string($fallback) && trim($fallback) !== '') {
            return trim($fallback);
        }

        return $default;
    }

    /** @param array<string,mixed>|null $stats */
    private function buildQuotaUsage(?array $stats, string $address, int $quotaMb): array
    {
        $mail = is_array($stats['mail'] ?? null) ? $stats['mail'] : [];
        $usageMap = is_array($mail['mailbox_usage'] ?? null) ? $mail['mailbox_usage'] : [];
        $lookupAddress = strtolower($address);
        $entry = is_array($usageMap[$lookupAddress] ?? null) ? $usageMap[$lookupAddress] : (is_array($usageMap[$address] ?? null) ? $usageMap[$address] : null);
        if ($entry === null) {
            return ['available' => false, 'used_bytes' => 0, 'used_mb' => 0.0, 'quota_mb' => max(0, $quotaMb), 'percent' => 0.0, 'percent_bar' => 0.0, 'truncated' => (bool) ($mail['mailbox_usage_truncated'] ?? false)];
        }

        $usedBytes = max(0, is_numeric($entry['used_bytes'] ?? null) ? (int) $entry['used_bytes'] : 0);
        $usedMb = round($usedBytes / 1024 / 1024, 1);
        $quota = max(0, $quotaMb);
        $percent = $quota > 0 ? round(($usedMb / $quota) * 100, 1) : 0.0;

        return ['available' => true, 'used_bytes' => $usedBytes, 'used_mb' => $usedMb, 'quota_mb' => $quota, 'percent' => $percent, 'percent_bar' => min(100.0, $percent), 'truncated' => (bool) ($mail['mailbox_usage_truncated'] ?? false)];
    }


    private function findLastMailboxProvisioningJob(string $address): ?array
    {
        $types = ['mailbox.create', 'mailbox.password.reset', 'mailbox.quota.update', 'mailbox.enable', 'mailbox.disable', 'mailbox.delete'];
        $latest = null;
        foreach ($types as $type) {
            $jobs = $this->jobRepository->findLatestByType($type, 25);
            foreach ($jobs as $job) {
                if ((string) ($job->getPayload()['address'] ?? '') !== $address) {
                    continue;
                }
                if ($latest === null || $job->getCreatedAt() > $latest->getCreatedAt()) {
                    $latest = $job;
                }
                break;
            }
        }
        if (!$latest instanceof Job) {
            return null;
        }

        return [
            'type' => $latest->getType(),
            'status' => $latest->getStatus()->value,
            'error' => $latest->getResult()?->getErrorMessage(),
        ];
    }

    private function resolvePort(mixed $value, int $default): int
    {
        if (is_numeric($value)) {
            $port = (int) $value;
            if ($port > 0) {
                return $port;
            }
        }

        return $default;
    }

    private function normalizeClientHost(string $host, string $fallback): string
    {
        $candidate = trim($host);
        if ($candidate === '') {
            return $fallback;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $candidate) === 1) {
            $parsedHost = parse_url($candidate, PHP_URL_HOST);
            if (is_string($parsedHost) && $parsedHost !== '') {
                $candidate = $parsedHost;
            }
        } elseif (str_contains($candidate, '/') || str_contains($candidate, '?') || str_contains($candidate, '#') || (str_contains($candidate, ':') && !str_contains($candidate, ']'))) {
            $parsedHost = parse_url(sprintf('tcp://%s', $candidate), PHP_URL_HOST);
            if (is_string($parsedHost) && $parsedHost !== '') {
                $candidate = $parsedHost;
            }
        }

        $candidate = trim($candidate, " \t\n\r\0\x0B.");

        return $candidate !== '' ? $candidate : $fallback;
    }

    private function normalizeEncryption(mixed $value, string $default): string
    {
        if (!is_string($value)) {
            return $default;
        }

        $value = strtolower(trim($value));
        if (!array_key_exists($value, self::ENCRYPTION_LABELS)) {
            return $default;
        }

        return $value;
    }
}
