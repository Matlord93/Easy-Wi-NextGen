<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\MailNode;
use App\Module\Core\Domain\Entity\User;
use App\Module\PanelAdmin\Application\MailNodeHealthAggregator;
use App\Module\PanelAdmin\Application\MailNodeMetricsAggregator;
use App\Repository\MailDomainRepository;
use App\Repository\MailNodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/api/v1/admin/mail/nodes')]
final class AdminMailNodeHealthController
{
    public function __construct(
        private readonly MailNodeRepository $mailNodeRepository,
        private readonly MailDomainRepository $mailDomainRepository,
        private readonly MailNodeHealthAggregator $healthAggregator,
        private readonly MailNodeMetricsAggregator $metricsAggregator,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route(path: '/health', methods: ['GET'])]
    public function health(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['error' => 'Forbidden'], JsonResponse::HTTP_FORBIDDEN);
        }

        return new JsonResponse($this->healthAggregator->aggregate(
            $this->mailNodeRepository->findBy([], ['name' => 'ASC']),
            $this->mailDomainRepository->findAll(),
        ));
    }

    #[Route(path: '/metrics', methods: ['GET'])]
    public function metrics(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['error' => 'Forbidden'], JsonResponse::HTTP_FORBIDDEN);
        }

        return new JsonResponse($this->metricsAggregator->aggregate(
            $this->mailNodeRepository->findBy([], ['name' => 'ASC']),
            $this->mailDomainRepository->findAll(),
        ));
    }

    #[Route(path: '/{id}/repair', methods: ['POST'])]
    public function repair(int $id, Request $request): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new JsonResponse(['error' => 'Forbidden'], JsonResponse::HTTP_FORBIDDEN);
        }

        $mailNode = $this->mailNodeRepository->find($id);
        if (!$mailNode instanceof MailNode) {
            return new JsonResponse(['error' => 'Mail node not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $agentId = $this->resolveAgentIdForMailNode($mailNode);
        if ($agentId === null) {
            return new JsonResponse(['error' => 'No agent/node mapped to this mail node.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $job = new Job('mail.ensure_base', [
                'role' => 'mail',
                'mail_node_id' => $mailNode->getId(),
                'node_id' => $agentId,
                'agent_id' => $agentId,
                'requested_by' => $actor->getId(),
                'source' => 'admin_mail_repair',
            ]);
            $this->entityManager->persist($job);
            $this->entityManager->flush();

            $this->auditLogger->log($actor, 'admin.mail.repair_queued', [
                'mail_node_id' => $mailNode->getId(),
                'node_id' => $agentId,
                'agent_id' => $agentId,
                'job_id' => $job->getId(),
            ]);

            return new JsonResponse([
                'ok' => true,
                'job_id' => $job->getId(),
                'message' => 'Mail repair job queued.',
            ]);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Unable to queue repair job.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    #[Route(path: '/{id}/roundcube/{action}', methods: ['POST'])]
    public function roundcubeAction(int $id, string $action, Request $request): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new JsonResponse(['error' => 'Forbidden'], JsonResponse::HTTP_FORBIDDEN);
        }

        if (!in_array($action, ['install', 'deploy'], true)) {
            return new JsonResponse(['error' => 'Unknown action.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $mailNode = $this->mailNodeRepository->find($id);
        if (!$mailNode instanceof MailNode) {
            return new JsonResponse(['error' => 'Mail node not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $agentId = $this->resolveAgentIdForMailNode($mailNode);
        if ($agentId === null) {
            return new JsonResponse(['error' => 'No agent/node mapped to this mail node.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $type = sprintf('roundcube.%s', $action);
        $payload = [
            'mail_node_id' => $mailNode->getId(),
            'node_id' => $agentId,
            'agent_id' => $agentId,
            'source' => 'admin_roundcube_action',
            'requested_by' => $actor->getId(),
            'webmail_url' => $mailNode->getRoundcubeUrl(),
            'imap_host' => $mailNode->getImapHost(),
            'smtp_host' => $mailNode->getSmtpHost(),
        ];

        try {
            $job = new Job($type, $payload);
            $this->entityManager->persist($job);
            $this->entityManager->flush();

            return new JsonResponse(['ok' => true, 'job_id' => $job->getId()]);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Unable to queue roundcube job.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->isAdmin();
    }

    private function resolveAgentIdForMailNode(MailNode $mailNode): ?string
    {
        $mailDomains = $this->mailDomainRepository->findBy(['node' => $mailNode]);
        foreach ($mailDomains as $mailDomain) {
            $webspace = $mailDomain->getDomain()->getWebspace();
            $agentId = $webspace?->getNode()?->getId();
            if (is_string($agentId) && $agentId !== '') {
                return $agentId;
            }
        }

        return null;
    }
}
