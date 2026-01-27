<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\Mailbox;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\DomainRepository;
use App\Repository\MailboxRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/mail')]
final class CustomerMailController
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

        $passwordHash = password_hash($password, PASSWORD_ARGON2ID);
        $secretPayload = $this->encryptionService->encrypt($password);

        $mailbox = new Mailbox(
            $domain,
            $localPart,
            $passwordHash,
            $secretPayload,
            $quota,
            $enabled,
        );

        $this->entityManager->persist($mailbox);
        $this->entityManager->flush();

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
            'errors' => $errors,
        ]), Response::HTTP_BAD_REQUEST);
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
}
