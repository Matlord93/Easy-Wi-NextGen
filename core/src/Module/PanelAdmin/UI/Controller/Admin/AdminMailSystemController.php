<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\MailDomain;
use App\Module\Core\Domain\Entity\MailPolicy;
use App\Module\Core\Domain\Entity\User;
use App\Repository\MailboxRepository;
use App\Repository\MailAliasRepository;
use App\Repository\MailDomainRepository;
use App\Repository\MailLogRepository;
use App\Repository\MailNodeRepository;
use App\Repository\MailPolicyRepository;
use App\Repository\QuotaPolicyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

#[Route(path: '/admin/mail')]
final class AdminMailSystemController
{
    public function __construct(
        private readonly MailNodeRepository $mailNodeRepository,
        private readonly QuotaPolicyRepository $quotaPolicyRepository,
        private readonly MailPolicyRepository $mailPolicyRepository,
        private readonly MailDomainRepository $mailDomainRepository,
        private readonly MailboxRepository $mailboxRepository,
        private readonly MailAliasRepository $mailAliasRepository,
        private readonly MailLogRepository $mailLogRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_mail_system', methods: ['GET'])]
    #[Route(path: '/', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $bindings = $this->mailDomainRepository->findBy([], ['updatedAt' => 'DESC']);
        $since = new \DateTimeImmutable('-24 hours');
        $levelSummary = $this->mailLogRepository->countByLevelSince($since);
        $sourceSummary = $this->mailLogRepository->countBySourceSince($since);
        $recentLogs = $this->mailLogRepository->findRecent(20);
        $suspiciousLogs = $this->mailLogRepository->findSuspiciousRecent(50);
        $policyPosture = $this->mailPolicyRepository->countSecurityPosture();

        return new Response($this->twig->render('admin/mail-system/index.html.twig', [
            'activeNav' => 'mail-system',
            'summary' => [
                'nodes' => $this->mailNodeRepository->count([]),
                'policies' => $this->quotaPolicyRepository->count([]),
                'domains' => count($bindings),
                'mailboxes' => $this->mailboxRepository->count([]),
                'aliases' => $this->mailAliasRepository->count([]),
            ],
            'status' => [
                'window_hours' => 24,
                'levels' => $levelSummary,
                'sources' => $sourceSummary,
                'recent' => array_map(static fn ($log): array => [
                    'created_at' => $log->getCreatedAt(),
                    'domain' => $log->getDomain()->getName(),
                    'source' => $log->getSource(),
                    'level' => $log->getLevel(),
                    'event_type' => $log->getEventType(),
                    'message' => $log->getMessage(),
                ], $recentLogs),
            ],
            'security' => [
                'policy_posture' => $policyPosture,
                'suspicious_count' => count($suspiciousLogs),
                'suspicious' => array_map(static fn ($log): array => [
                    'created_at' => $log->getCreatedAt(),
                    'domain_id' => $log->getDomain()->getId(),
                    'domain' => $log->getDomain()->getName(),
                    'source' => $log->getSource(),
                    'level' => $log->getLevel(),
                    'event_type' => $log->getEventType(),
                    'message' => $log->getMessage(),
                ], $suspiciousLogs),
            ],
            'csrf' => [
                'contain_domain' => $this->csrfTokenManager->getToken('mail_contain_domain')->getValue(),
            ],
            'bindings' => array_map(static fn (MailDomain $binding): array => [
                'domain' => $binding->getDomainName(),
                'customer' => $binding->getOwner()->getEmail(),
                'node' => $binding->getNode()->getName(),
                'policy' => $binding->getQuotaPolicy()?->getName() ?? '—',
                'mail_enabled' => $binding->isMailEnabled(),
                'dkim' => $binding->getDkimStatus(),
                'spf' => $binding->getSpfStatus(),
                'dmarc' => $binding->getDmarcStatus(),
                'mx' => $binding->getMxStatus(),
                'tls' => $binding->getTlsStatus(),
                'updated_at' => $binding->getUpdatedAt(),
            ], $bindings),
        ]));
    }

    #[Route(path: '/security/contain-domain', name: 'admin_mail_security_contain_domain', methods: ['POST'])]
    public function containDomain(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $csrf = new CsrfToken('mail_contain_domain', (string) $request->request->get('_token', ''));
        if (!$this->csrfTokenManager->isTokenValid($csrf)) {
            return new Response('Invalid CSRF token.', Response::HTTP_UNAUTHORIZED);
        }

        $domainId = (int) $request->request->get('domain_id', 0);
        $domain = $domainId > 0 ? $this->mailDomainRepository->find($domainId) : null;
        if ($domain === null) {
            return new Response('Domain not found.', Response::HTTP_NOT_FOUND);
        }

        $policy = $this->mailPolicyRepository->findOneByDomain($domain);
        if (!$policy instanceof MailPolicy) {
            $policy = new MailPolicy($domain);
            $this->entityManager->persist($policy);
        }

        $policy->apply(
            true,
            min($policy->getMaxRecipients(), 25),
            min($policy->getMaxHourlyEmails(), 200),
            false,
            MailPolicy::SPAM_HIGH,
            true,
        );

        $this->auditLogger->log($actor, 'mail.security.containment_applied', [
            'domain_id' => $domain->getId(),
            'domain' => $domain->getName(),
            'policy' => $policy->toArray(),
        ]);

        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/admin/mail']);
    }

}
