<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Job;
use App\Entity\Mailbox;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\DomainRepository;
use App\Repository\MailboxRepository;
use App\Service\AuditLogger;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class MailboxApiController
{
    public function __construct(
        private readonly MailboxRepository $mailboxRepository,
        private readonly DomainRepository $domainRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly EncryptionService $encryptionService,
    ) {
    }

    #[Route(path: '/api/mailboxes', name: 'mailboxes_list', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/mailboxes', name: 'mailboxes_list_v1', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $actor = $this->requireUser($request);

        $mailboxes = $actor->getType() === UserType::Admin
            ? $this->mailboxRepository->findBy([], ['updatedAt' => 'DESC'])
            : $this->mailboxRepository->findByCustomer($actor);

        return new JsonResponse([
            'mailboxes' => array_map(fn (Mailbox $mailbox) => $this->normalizeMailbox($mailbox), $mailboxes),
        ]);
    }

    #[Route(path: '/api/mailboxes', name: 'mailboxes_create', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/mailboxes', name: 'mailboxes_create_v1', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $actor = $this->requireUser($request);
        $payload = $this->parseJsonPayload($request);

        $formData = $this->validatePayload($payload, true);
        if ($formData['error'] instanceof JsonResponse) {
            return $formData['error'];
        }

        $domain = $formData['domain'];
        if (!$this->canAccessDomain($actor, $domain)) {
            return new JsonResponse(['error' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $passwordHash = password_hash($formData['password'], PASSWORD_ARGON2ID);
        $secretPayload = $this->encryptionService->encrypt($formData['password']);

        $mailbox = new Mailbox(
            $domain,
            $formData['local_part'],
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

        return new JsonResponse([
            'mailbox' => $this->normalizeMailbox($mailbox),
            'job_id' => $job->getId(),
        ], JsonResponse::HTTP_CREATED);
    }

    #[Route(path: '/api/mailboxes/{id}/quota', name: 'mailboxes_quota_update', methods: ['PATCH'])]
    #[Route(path: '/api/v1/customer/mailboxes/{id}/quota', name: 'mailboxes_quota_update_v1', methods: ['PATCH'])]
    public function updateQuota(Request $request, int $id): JsonResponse
    {
        $actor = $this->requireUser($request);
        $mailbox = $this->mailboxRepository->find($id);
        if ($mailbox === null) {
            return new JsonResponse(['error' => 'Mailbox not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$this->canAccessMailbox($actor, $mailbox)) {
            return new JsonResponse(['error' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $payload = $this->parseJsonPayload($request);
        $quotaValue = $payload['quota'] ?? null;
        if ($quotaValue === null || $quotaValue === '' || !is_numeric($quotaValue)) {
            return new JsonResponse(['error' => 'Quota must be numeric.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $quota = (int) $quotaValue;
        if ($quota < 0) {
            return new JsonResponse(['error' => 'Quota must be zero or positive.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($quota === $mailbox->getQuota()) {
            return new JsonResponse(['mailbox' => $this->normalizeMailbox($mailbox)]);
        }

        $previousQuota = $mailbox->getQuota();
        $mailbox->setQuota($quota);
        $job = $this->queueMailboxJob('mailbox.quota.update', $mailbox, [
            'quota_mb' => (string) $quota,
        ]);

        $this->auditLogger->log($actor, 'mailbox.quota_updated', [
            'mailbox_id' => $mailbox->getId(),
            'address' => $mailbox->getAddress(),
            'previous_quota' => $previousQuota,
            'quota' => $quota,
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        return new JsonResponse([
            'mailbox' => $this->normalizeMailbox($mailbox),
            'job_id' => $job->getId(),
        ]);
    }

    #[Route(path: '/api/mailboxes/{id}/status', name: 'mailboxes_status_update', methods: ['PATCH'])]
    #[Route(path: '/api/v1/customer/mailboxes/{id}/status', name: 'mailboxes_status_update_v1', methods: ['PATCH'])]
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $actor = $this->requireUser($request);
        $mailbox = $this->mailboxRepository->find($id);
        if ($mailbox === null) {
            return new JsonResponse(['error' => 'Mailbox not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$this->canAccessMailbox($actor, $mailbox)) {
            return new JsonResponse(['error' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $payload = $this->parseJsonPayload($request);
        $enabledValue = $payload['enabled'] ?? null;
        if (!is_bool($enabledValue) && !is_numeric($enabledValue) && !is_string($enabledValue)) {
            return new JsonResponse(['error' => 'Enabled must be boolean.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $enabled = filter_var($enabledValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            return new JsonResponse(['error' => 'Enabled must be boolean.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($enabled === $mailbox->isEnabled()) {
            return new JsonResponse(['mailbox' => $this->normalizeMailbox($mailbox)]);
        }

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

        return new JsonResponse([
            'mailbox' => $this->normalizeMailbox($mailbox),
            'job_id' => $job->getId(),
        ]);
    }

    #[Route(path: '/api/mailboxes/{id}/password', name: 'mailboxes_password_reset', methods: ['PATCH'])]
    #[Route(path: '/api/v1/customer/mailboxes/{id}/password', name: 'mailboxes_password_reset_v1', methods: ['PATCH'])]
    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $actor = $this->requireUser($request);
        $mailbox = $this->mailboxRepository->find($id);
        if ($mailbox === null) {
            return new JsonResponse(['error' => 'Mailbox not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$this->canAccessMailbox($actor, $mailbox)) {
            return new JsonResponse(['error' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $payload = $this->parseJsonPayload($request);
        $password = trim((string) ($payload['password'] ?? ''));
        if ($password === '') {
            return new JsonResponse(['error' => 'Password is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        if (mb_strlen($password) < 8) {
            return new JsonResponse(['error' => 'Password must be at least 8 characters.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $passwordHash = password_hash($password, PASSWORD_ARGON2ID);
        $secretPayload = $this->encryptionService->encrypt($password);
        $mailbox->setPassword($passwordHash, $secretPayload);

        $job = $this->queueMailboxJob('mailbox.password.reset', $mailbox, [
            'password_hash' => $passwordHash,
        ]);

        $this->auditLogger->log($actor, 'mailbox.password_reset', [
            'mailbox_id' => $mailbox->getId(),
            'address' => $mailbox->getAddress(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        return new JsonResponse([
            'mailbox' => $this->normalizeMailbox($mailbox),
            'job_id' => $job->getId(),
        ]);
    }

    private function requireUser(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    private function parseJsonPayload(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\JsonException $exception) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid JSON payload.', $exception);
        }
    }

    private function validatePayload(array $payload, bool $requirePassword): array
    {
        $domainId = $payload['domain_id'] ?? null;
        $localPart = strtolower(trim((string) ($payload['local_part'] ?? '')));
        $password = trim((string) ($payload['password'] ?? ''));
        $quotaValue = $payload['quota'] ?? null;
        $enabled = isset($payload['enabled']) ? filter_var($payload['enabled'], FILTER_VALIDATE_BOOLEAN) : true;

        if (!is_numeric($domainId)) {
            return ['error' => new JsonResponse(['error' => 'Domain is required.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        $domain = $this->domainRepository->find((int) $domainId);
        if ($domain === null) {
            return ['error' => new JsonResponse(['error' => 'Domain not found.'], JsonResponse::HTTP_NOT_FOUND)];
        }

        if ($localPart === '' || !preg_match('/^[a-z0-9._+\-]+$/i', $localPart) || str_contains($localPart, '@')) {
            return ['error' => new JsonResponse(['error' => 'Invalid mailbox name.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        if ($requirePassword && $password === '') {
            return ['error' => new JsonResponse(['error' => 'Password is required.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        if ($password !== '' && mb_strlen($password) < 8) {
            return ['error' => new JsonResponse(['error' => 'Password must be at least 8 characters.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        if ($quotaValue === null || $quotaValue === '' || !is_numeric($quotaValue)) {
            return ['error' => new JsonResponse(['error' => 'Quota must be numeric.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        $quota = (int) $quotaValue;
        if ($quota < 0) {
            return ['error' => new JsonResponse(['error' => 'Quota must be zero or positive.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        $address = sprintf('%s@%s', $localPart, $domain->getName());
        $existing = $this->mailboxRepository->findOneByAddress($address);
        if ($existing !== null) {
            return ['error' => new JsonResponse(['error' => 'Mailbox address already exists.'], JsonResponse::HTTP_CONFLICT)];
        }

        return [
            'domain' => $domain,
            'local_part' => $localPart,
            'password' => $password,
            'quota' => $quota,
            'enabled' => (bool) $enabled,
            'error' => null,
        ];
    }

    private function canAccessDomain(User $actor, \App\Entity\Domain $domain): bool
    {
        if ($actor->getType() === UserType::Admin) {
            return true;
        }

        return $domain->getCustomer()->getId() === $actor->getId();
    }

    private function canAccessMailbox(User $actor, Mailbox $mailbox): bool
    {
        if ($actor->getType() === UserType::Admin) {
            return true;
        }

        return $mailbox->getCustomer()->getId() === $actor->getId();
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

    private function normalizeMailbox(Mailbox $mailbox): array
    {
        return [
            'id' => $mailbox->getId(),
            'address' => $mailbox->getAddress(),
            'domain' => [
                'id' => $mailbox->getDomain()->getId(),
                'name' => $mailbox->getDomain()->getName(),
            ],
            'quota' => $mailbox->getQuota(),
            'enabled' => $mailbox->isEnabled(),
            'updated_at' => $mailbox->getUpdatedAt()->format(DATE_RFC3339),
        ];
    }
}
