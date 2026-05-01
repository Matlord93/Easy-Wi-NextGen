<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\MailDomain;
use App\Module\Core\Domain\Entity\MailNode;
use App\Module\Core\Domain\Entity\QuotaPolicy;
use App\Module\Core\Domain\Entity\User;
use App\Repository\DomainRepository;
use App\Repository\JobRepository;
use App\Repository\MailDomainRepository;
use App\Repository\MailNodeRepository;
use App\Repository\QuotaPolicyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/api/v1/admin/mail-platform')]
final class AdminMailPlatformController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MailNodeRepository $nodeRepository,
        private readonly QuotaPolicyRepository $policyRepository,
        private readonly DomainRepository $domainRepository,
        private readonly MailDomainRepository $mailDomainRepository,
        private readonly JobRepository $jobRepository,
        private readonly EncryptionService $encryptionService,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route(path: '/nodes', methods: ['POST'])]
    public function createNode(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['error' => 'Forbidden'], JsonResponse::HTTP_FORBIDDEN);
        }
        $p = $request->toArray();
        $node = new MailNode((string) $p['name'], (string) $p['imap_host'], (int) $p['imap_port'], (string) $p['smtp_host'], (int) $p['smtp_port'], (string) $p['roundcube_url']);
        $this->entityManager->persist($node);
        $this->entityManager->flush();

        return new JsonResponse(['id' => $node->getId()], JsonResponse::HTTP_CREATED);
    }

    #[Route(path: '/policies', methods: ['POST'])]
    public function createPolicy(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['error' => 'Forbidden'], JsonResponse::HTTP_FORBIDDEN);
        }
        $p = $request->toArray();
        $policy = new QuotaPolicy((string) $p['name'], (int) $p['max_accounts'], (int) $p['max_domain_quota_mb'], (int) $p['max_mailbox_quota_mb']);
        $this->entityManager->persist($policy);
        $this->entityManager->flush();

        return new JsonResponse(['id' => $policy->getId()], JsonResponse::HTTP_CREATED);
    }

    #[Route(path: '/domains/{id}/bind', methods: ['POST'])]
    public function bindDomain(Request $request, int $id): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['error' => 'Forbidden'], JsonResponse::HTTP_FORBIDDEN);
        }

        $domain = $this->domainRepository->find($id);
        if ($domain === null) {
            return new JsonResponse(['error' => 'Domain not found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $p = $request->toArray();
        $node = $this->nodeRepository->find((int) ($p['mail_node_id'] ?? 0));
        if ($node === null) {
            return new JsonResponse(['error' => 'Mail node not found'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $policy = isset($p['quota_policy_id']) ? $this->policyRepository->find((int) $p['quota_policy_id']) : null;
        $mailDomain = $this->mailDomainRepository->findOneByDomain($domain);
        if ($mailDomain === null) {
            $mailDomain = new MailDomain($domain, $node, $policy);
        } else {
            $mailDomain->setNode($node);
            $mailDomain->setQuotaPolicy($policy);
        }
        $this->entityManager->persist($mailDomain);

        $activeDeployJob = $this->jobRepository->findActiveByTypeAndPayloadField('roundcube.deploy', 'domain', $domain->getName());
        if ($activeDeployJob !== null) {
            return new JsonResponse([
                'mail_domain_id' => $mailDomain->getId(),
                'job_id' => $activeDeployJob->getId(),
                'job_status' => $activeDeployJob->getStatus()->value,
                'idempotent' => true,
            ]);
        }

        $webspace = $domain->getWebspace();
        $job = new Job('roundcube.deploy', [
            'domain' => $domain->getName(),
            'mail_node_id' => (string) $node->getId(),
            'roundcube_url' => $node->getRoundcubeUrl(),
            'agent_id' => $webspace !== null ? (string) $webspace->getNode()->getId() : null,
        ]);
        $job->setMaxAttempts(5);
        $this->entityManager->persist($job);

        $actor = $request->attributes->get('current_user');
        if ($actor instanceof User) {
            $this->auditLogger->log($actor, 'roundcube.deploy_requested', [
                'domain_id' => $domain->getId(),
                'domain' => $domain->getName(),
                'mail_node_id' => $node->getId(),
                'job_id' => $job->getId(),
                'max_attempts' => $job->getMaxAttempts(),
            ]);
        }

        $this->entityManager->flush();

        return new JsonResponse(['mail_domain_id' => $mailDomain->getId(), 'job_id' => $job->getId(), 'job_status' => $job->getStatus()->value]);
    }

    #[Route(path: '/domains/{id}/dkim/rotate', methods: ['POST'])]
    public function rotateDkimKey(Request $request, int $id): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['error' => 'Forbidden'], JsonResponse::HTTP_FORBIDDEN);
        }

        $domain = $this->domainRepository->find($id);
        if ($domain === null) {
            return new JsonResponse(['error' => 'Domain not found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $mailDomain = $this->mailDomainRepository->findOneByDomain($domain);
        if ($mailDomain === null) {
            return new JsonResponse(['error' => 'Domain is not mail-enabled'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $p = $request->toArray();
        $selector = strtolower(trim((string) ($p['selector'] ?? 'default')));
        $mailDomain->rotateDkimKey($selector);

        $webspace = $domain->getWebspace();
        $job = new Job('mail.dkim.rotate', [
            'domain_id' => (string) $domain->getId(),
            'domain' => $domain->getName(),
            'selector' => $mailDomain->getDkimSelector(),
            'agent_id' => $webspace !== null ? (string) $webspace->getNode()->getId() : null,
        ]);
        $this->entityManager->persist($job);

        $actor = $request->attributes->get('current_user');
        if ($actor instanceof User) {
            $this->auditLogger->log($actor, 'mail.dkim_rotated', [
                'domain_id' => $domain->getId(),
                'domain' => $domain->getName(),
                'selector' => $mailDomain->getDkimSelector(),
                'job_id' => $job->getId(),
            ]);
        }

        $this->entityManager->flush();

        return new JsonResponse(['job_id' => $job->getId(), 'selector' => $mailDomain->getDkimSelector()]);
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->isAdmin();
    }
}
