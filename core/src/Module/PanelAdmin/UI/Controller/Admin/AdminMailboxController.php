<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\Mailbox;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\DomainRepository;
use App\Repository\MailboxRepository;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/mailboxes')]
final class AdminMailboxController
{
    public function __construct(
        private readonly MailboxRepository $mailboxRepository,
        private readonly DomainRepository $domainRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly EncryptionService $encryptionService,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_mailboxes', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $mailboxes = $this->mailboxRepository->findBy([], ['updatedAt' => 'DESC']);
        $domains = $this->domainRepository->findBy([], ['name' => 'ASC']);

        return new Response($this->twig->render('admin/mailboxes/index.html.twig', [
            'mailboxes' => $this->normalizeMailboxes($mailboxes),
            'domains' => $domains,
            'summary' => $this->buildSummary($mailboxes),
            'form' => $this->buildFormContext(),
            'activeNav' => 'mailboxes',
        ]));
    }

    #[Route(path: '/table', name: 'admin_mailboxes_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $mailboxes = $this->mailboxRepository->findBy([], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/mailboxes/_table.html.twig', [
            'mailboxes' => $this->normalizeMailboxes($mailboxes),
        ]));
    }

    #[Route(path: '/form', name: 'admin_mailboxes_form', methods: ['GET'])]
    public function form(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $domains = $this->domainRepository->findBy([], ['name' => 'ASC']);

        return new Response($this->twig->render('admin/mailboxes/_form.html.twig', [
            'domains' => $domains,
            'form' => $this->buildFormContext(),
        ]));
    }

    #[Route(path: '/{id}/edit', name: 'admin_mailboxes_edit', methods: ['GET'])]
    public function edit(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $mailbox = $this->mailboxRepository->find($id);
        if ($mailbox === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $domains = $this->domainRepository->findBy([], ['name' => 'ASC']);

        return new Response($this->twig->render('admin/mailboxes/_form.html.twig', [
            'domains' => $domains,
            'form' => $this->buildFormContext($mailbox),
        ]));
    }

    #[Route(path: '', name: 'admin_mailboxes_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $formData = $this->parsePayload($request, true);
        $domains = $this->domainRepository->findBy([], ['name' => 'ASC']);

        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($domains, $formData, Response::HTTP_BAD_REQUEST);
        }

        $domain = $formData['domain'];
        $localPart = $formData['local_part'];
        $passwordHash = password_hash($formData['password'], PASSWORD_ARGON2ID);
        $secretPayload = $this->encryptionService->encrypt($formData['password']);

        $mailbox = new Mailbox(
            $domain,
            $localPart,
            $passwordHash,
            $secretPayload,
            $formData['quota'],
            $formData['enabled'],
        );

        $this->entityManager->persist($mailbox);
        $this->entityManager->flush();

        $job = $this->queueMailboxJob('mailbox.create', $mailbox, [
            'password_hash' => $passwordHash,
            'quota_mb' => (string) $mailbox->getQuota(),
            'enabled' => $mailbox->isEnabled() ? 'true' : 'false',
        ]);

        $this->auditLogger->log($actor, 'mailbox.created', [
            'mailbox_id' => $mailbox->getId(),
            'domain_id' => $domain->getId(),
            'address' => $mailbox->getAddress(),
            'quota' => $mailbox->getQuota(),
            'enabled' => $mailbox->isEnabled(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/mailboxes/_form.html.twig', [
            'domains' => $domains,
            'form' => $this->buildFormContext(),
        ]));
        $response->headers->set('HX-Trigger', 'mailboxes-changed');

        return $response;
    }

    #[Route(path: '/{id}', name: 'admin_mailboxes_update', methods: ['POST'])]
    public function update(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $mailbox = $this->mailboxRepository->find($id);
        if ($mailbox === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $formData = $this->parsePayload($request, false, $mailbox);
        $domains = $this->domainRepository->findBy([], ['name' => 'ASC']);

        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($domains, $formData, Response::HTTP_BAD_REQUEST, $mailbox);
        }

        $jobs = [];

        if ($formData['quota'] !== $mailbox->getQuota()) {
            $previousQuota = $mailbox->getQuota();
            $mailbox->setQuota($formData['quota']);

            $job = $this->queueMailboxJob('mailbox.quota.update', $mailbox, [
                'quota_mb' => (string) $mailbox->getQuota(),
            ]);
            $jobs[] = $job;
            $this->auditLogger->log($actor, 'mailbox.quota_updated', [
                'mailbox_id' => $mailbox->getId(),
                'address' => $mailbox->getAddress(),
                'previous_quota' => $previousQuota,
                'quota' => $mailbox->getQuota(),
                'job_id' => $job->getId(),
            ]);
        }

        if ($formData['enabled'] !== $mailbox->isEnabled()) {
            $previousEnabled = $mailbox->isEnabled();
            $mailbox->setEnabled($formData['enabled']);

            $jobType = $mailbox->isEnabled() ? 'mailbox.enable' : 'mailbox.disable';
            $job = $this->queueMailboxJob($jobType, $mailbox, [
                'enabled' => $mailbox->isEnabled() ? 'true' : 'false',
            ]);
            $jobs[] = $job;
            $this->auditLogger->log($actor, $mailbox->isEnabled() ? 'mailbox.enabled' : 'mailbox.disabled', [
                'mailbox_id' => $mailbox->getId(),
                'address' => $mailbox->getAddress(),
                'previous_enabled' => $previousEnabled,
                'enabled' => $mailbox->isEnabled(),
                'job_id' => $job->getId(),
            ]);
        }

        if ($formData['password'] !== '') {
            $passwordHash = password_hash($formData['password'], PASSWORD_ARGON2ID);
            $secretPayload = $this->encryptionService->encrypt($formData['password']);
            $mailbox->setPassword($passwordHash, $secretPayload);

            $job = $this->queueMailboxJob('mailbox.password.reset', $mailbox, [
                'password_hash' => $passwordHash,
            ]);
            $jobs[] = $job;
            $this->auditLogger->log($actor, 'mailbox.password_reset', [
                'mailbox_id' => $mailbox->getId(),
                'address' => $mailbox->getAddress(),
                'job_id' => $job->getId(),
            ]);
        }

        if ($jobs !== []) {
            $this->entityManager->flush();
        }

        $response = new Response($this->twig->render('admin/mailboxes/_form.html.twig', [
            'domains' => $domains,
            'form' => $this->buildFormContext($mailbox),
        ]));
        $response->headers->set('HX-Trigger', 'mailboxes-changed');

        return $response;
    }

    #[Route(path: '/{id}/enable', name: 'admin_mailboxes_enable', methods: ['POST'])]
    public function enable(Request $request, int $id): Response
    {
        return $this->setMailboxStatus($request, $id, true);
    }

    #[Route(path: '/{id}/disable', name: 'admin_mailboxes_disable', methods: ['POST'])]
    public function disable(Request $request, int $id): Response
    {
        return $this->setMailboxStatus($request, $id, false);
    }

    private function setMailboxStatus(Request $request, int $id, bool $enabled): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $mailbox = $this->mailboxRepository->find($id);
        if ($mailbox === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        if ($mailbox->isEnabled() !== $enabled) {
            $previousEnabled = $mailbox->isEnabled();
            $mailbox->setEnabled($enabled);

            $jobType = $enabled ? 'mailbox.enable' : 'mailbox.disable';
            $job = $this->queueMailboxJob($jobType, $mailbox, [
                'enabled' => $enabled ? 'true' : 'false',
            ]);

            $this->auditLogger->log($actor, $enabled ? 'mailbox.enabled' : 'mailbox.disabled', [
                'mailbox_id' => $mailbox->getId(),
                'address' => $mailbox->getAddress(),
                'previous_enabled' => $previousEnabled,
                'enabled' => $enabled,
                'job_id' => $job->getId(),
            ]);

            $this->entityManager->flush();
        }

        $mailboxes = $this->mailboxRepository->findBy([], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/mailboxes/_table.html.twig', [
            'mailboxes' => $this->normalizeMailboxes($mailboxes),
        ]));
    }

    private function parsePayload(Request $request, bool $requireIdentity, ?Mailbox $mailbox = null): array
    {
        $domainId = $request->request->get('domain_id');
        $localPart = strtolower(trim((string) $request->request->get('local_part', '')));
        $password = trim((string) $request->request->get('password', ''));
        $quotaValue = $request->request->get('quota');
        $enabled = $request->request->getBoolean('enabled', true);
        $errors = [];

        $domain = null;
        if (is_numeric($domainId)) {
            $domain = $this->domainRepository->find((int) $domainId);
        }

        if ($requireIdentity) {
            if ($domain === null) {
                $errors[] = 'Domain is required.';
            }

            if ($localPart === '') {
                $errors[] = 'Mailbox name is required.';
            } elseif (!preg_match('/^[a-z0-9._+\-]+$/i', $localPart)) {
                $errors[] = 'Mailbox name contains invalid characters.';
            } elseif (str_contains($localPart, '@')) {
                $errors[] = 'Mailbox name must not include @.';
            }
        } elseif ($mailbox !== null) {
            $domain = $mailbox->getDomain();
            $localPart = $mailbox->getLocalPart();
        }

        if ($password === '' && $requireIdentity) {
            $errors[] = 'Password is required.';
        }

        if ($password !== '' && mb_strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        $quota = null;
        if ($quotaValue === null || $quotaValue === '') {
            $errors[] = 'Quota is required.';
        } elseif (!is_numeric($quotaValue)) {
            $errors[] = 'Quota must be numeric.';
        } else {
            $quota = (int) $quotaValue;
            if ($quota < 0) {
                $errors[] = 'Quota must be zero or positive.';
            }
        }

        if ($domain !== null && $localPart !== '' && $requireIdentity) {
            $address = sprintf('%s@%s', $localPart, $domain->getName());
            $existing = $this->mailboxRepository->findOneByAddress($address);
            if ($existing !== null) {
                $errors[] = 'Mailbox address already exists.';
            }
        }

        return [
            'domain' => $domain,
            'local_part' => $localPart,
            'password' => $password,
            'quota' => $quota ?? 0,
            'enabled' => $enabled,
            'errors' => $errors,
        ];
    }

    private function buildFormContext(?Mailbox $mailbox = null, ?array $override = null): array
    {
        $data = [
            'id' => $mailbox?->getId(),
            'domain_id' => $mailbox?->getDomain()->getId(),
            'domain_name' => $mailbox?->getDomain()->getName(),
            'address' => $mailbox?->getAddress(),
            'local_part' => $mailbox?->getLocalPart() ?? '',
            'quota' => $mailbox?->getQuota() ?? 1024,
            'enabled' => $mailbox?->isEnabled() ?? true,
            'errors' => [],
            'action' => $mailbox === null ? 'create' : 'update',
            'submit_label' => $mailbox === null ? 'Create Mailbox' : 'Update Mailbox',
            'submit_color' => $mailbox === null ? 'bg-indigo-600' : 'bg-amber-500',
            'action_url' => $mailbox === null ? '/admin/mailboxes' : sprintf('/admin/mailboxes/%d', $mailbox->getId()),
        ];

        if ($override !== null) {
            $data = array_merge($data, $override);
        }

        return $data;
    }

    private function renderFormWithErrors(array $domains, array $formData, int $status, ?Mailbox $mailbox = null): Response
    {
        $formContext = $this->buildFormContext($mailbox, [
            'domain_id' => $formData['domain']?->getId(),
            'domain_name' => $formData['domain']?->getName(),
            'local_part' => $formData['local_part'],
            'address' => $formData['domain'] !== null ? sprintf('%s@%s', $formData['local_part'], $formData['domain']->getName()) : null,
            'quota' => $formData['quota'],
            'enabled' => $formData['enabled'],
            'errors' => $formData['errors'],
        ]);

        return new Response($this->twig->render('admin/mailboxes/_form.html.twig', [
            'domains' => $domains,
            'form' => $formContext,
        ]), $status);
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
    private function buildSummary(array $mailboxes): array
    {
        $summary = [
            'total' => count($mailboxes),
            'enabled' => 0,
            'disabled' => 0,
            'quotaTotal' => 0,
        ];

        foreach ($mailboxes as $mailbox) {
            if ($mailbox->isEnabled()) {
                $summary['enabled']++;
            } else {
                $summary['disabled']++;
            }
            $summary['quotaTotal'] += $mailbox->getQuota();
        }

        return $summary;
    }

    /**
     * @param Mailbox[] $mailboxes
     */
    private function normalizeMailboxes(array $mailboxes): array
    {
        return array_map(function (Mailbox $mailbox): array {
            return [
                'id' => $mailbox->getId(),
                'address' => $mailbox->getAddress(),
                'domain' => [
                    'id' => $mailbox->getDomain()->getId(),
                    'name' => $mailbox->getDomain()->getName(),
                ],
                'customer' => [
                    'id' => $mailbox->getCustomer()->getId(),
                    'email' => $mailbox->getCustomer()->getEmail(),
                ],
                'quota' => $mailbox->getQuota(),
                'enabled' => $mailbox->isEnabled(),
                'updatedAt' => $mailbox->getUpdatedAt(),
            ];
        }, $mailboxes);
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->isAdmin();
    }
}
