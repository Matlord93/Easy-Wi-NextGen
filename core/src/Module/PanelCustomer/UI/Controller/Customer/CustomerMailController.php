<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Application\MailLimitEnforcer;
use App\Module\Core\Application\MailPasswordHasher;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\Mailbox;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\DomainRepository;
use App\Repository\MailboxRepository;
use App\Repository\MailDomainRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/mail')]
final class CustomerMailController
{
    private const DEFAULT_IMAP_PORT = 993;
    private const DEFAULT_SMTP_PORT = 587;
    private const DEFAULT_IMAP_ENCRYPTION = 'ssl_tls';
    private const DEFAULT_SMTP_ENCRYPTION = 'starttls';

    private const ENCRYPTION_LABELS = [
        'ssl_tls' => 'SSL/TLS',
        'starttls' => 'STARTTLS',
        'none' => 'None',
    ];

    public function __construct(
        private readonly MailboxRepository $mailboxRepository,
        private readonly DomainRepository $domainRepository,
        private readonly MailDomainRepository $mailDomainRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly EncryptionService $encryptionService,
        private readonly MailPasswordHasher $mailPasswordHasher,
        private readonly MailLimitEnforcer $mailLimitEnforcer,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_mail', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $mailboxes = $this->mailboxRepository->findByCustomer($customer);
        $domains = $this->domainRepository->findByCustomer($customer);

        return new Response($this->twig->render('customer/mail/index.html.twig', [
            'activeNav' => 'mail',
            'mailboxes' => $this->normalizeMailboxes($mailboxes),
            'domains' => $domains,
            'client_settings' => $this->buildClientSettings($domains),
            'roundcube_bindings' => $this->buildRoundcubeBindings($domains),
        ]));
    }

    #[Route(path: '', name: 'customer_mail_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $customer = $this->requireCustomer($request);

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

        $job = $this->queueMailboxJob('mailbox.create', $mailbox, [
            'password_hash' => $passwordHash,
            'quota_mb' => (string) $mailbox->getQuota(),
            'enabled' => $mailbox->isEnabled() ? 'true' : 'false',
        ]);

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
            $job = $this->queueMailboxJob('mailbox.quota.update', $mailbox, [
                'quota_mb' => (string) $quota,
            ]);

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
        $mailbox = $this->loadMailbox($customer, $id);
        if ($mailbox === null) {
            return $this->renderWithErrors($customer, ['Mailbox not found.']);
        }

        $enabled = $request->request->get('enabled') === '1';
        if ($enabled !== $mailbox->isEnabled()) {
            $previousEnabled = $mailbox->isEnabled();
            $mailbox->setEnabled($enabled);
            $jobType = $enabled ? 'mailbox.enable' : 'mailbox.disable';
            $job = $this->queueMailboxJob($jobType, $mailbox, [
                'enabled' => $enabled ? 'true' : 'false',
            ]);

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

        $passwordHash = $this->mailPasswordHasher->hash($password);
        $secretPayload = $this->encryptionService->encrypt($password);
        $mailbox->setPassword($passwordHash, $secretPayload);
        $job = $this->queueMailboxJob('mailbox.password.reset', $mailbox, [
            'password_hash' => $passwordHash,
        ]);

        $this->auditLogger->log($customer, 'mailbox.password_reset', [
            'mailbox_id' => $mailbox->getId(),
            'address' => $mailbox->getAddress(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/mail']);
    }

    #[Route(path: '/{id}/delete', name: 'customer_mail_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
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
        $domains = $this->domainRepository->findByCustomer($customer);

        return new Response($this->twig->render('customer/mail/index.html.twig', [
            'activeNav' => 'mail',
            'mailboxes' => $this->normalizeMailboxes($mailboxes),
            'domains' => $domains,
            'client_settings' => $this->buildClientSettings($domains),
            'roundcube_bindings' => $this->buildRoundcubeBindings($domains),
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
        $payload = array_merge([
            'mailbox_id' => (string) ($mailbox->getId() ?? ''),
            'domain_id' => (string) $domain->getId(),
            'domain' => $domain->getName(),
            'local_part' => $mailbox->getLocalPart(),
            'address' => $mailbox->getAddress(),
            'customer_id' => (string) $mailbox->getCustomer()->getId(),
            'agent_id' => $domain->getWebspace()->getNode()->getId(),
        ], $extraPayload);

        $job = new Job($type, $payload);
        $this->entityManager->persist($job);

        return $job;
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
            $node = $domain->getWebspace()->getNode();
            $metadata = $node->getMetadata();
            $metadata = is_array($metadata) ? $metadata : [];
            $mailDomain = $this->mailDomainRepository->findOneByDomain($domain);
            if ($mailDomain !== null) {
                $mailNode = $mailDomain->getNode();
                $settings[] = [
                    'domain' => $domain->getName(),
                    'imap' => [
                        'host' => $mailNode->getImapHost(),
                        'port' => $mailNode->getImapPort(),
                        'encryption' => self::ENCRYPTION_LABELS[self::DEFAULT_IMAP_ENCRYPTION],
                    ],
                    'smtp' => [
                        'host' => $mailNode->getSmtpHost(),
                        'port' => $mailNode->getSmtpPort(),
                        'encryption' => self::ENCRYPTION_LABELS[self::DEFAULT_SMTP_ENCRYPTION],
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
                    'host' => $imapHost,
                    'port' => $imapPort,
                    'encryption' => self::ENCRYPTION_LABELS[$imapEncryption] ?? $imapEncryption,
                ],
                'smtp' => [
                    'host' => $smtpHost,
                    'port' => $smtpPort,
                    'encryption' => self::ENCRYPTION_LABELS[$smtpEncryption] ?? $smtpEncryption,
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
