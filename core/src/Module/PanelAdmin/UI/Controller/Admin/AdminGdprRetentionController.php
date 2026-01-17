<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\RetentionPolicy;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\RetentionPolicyRepository;
use App\Module\Core\Application\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/gdpr/retention')]
final class AdminGdprRetentionController
{
    public function __construct(
        private readonly RetentionPolicyRepository $retentionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_gdpr_retention', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $policy = $this->retentionRepository->getCurrent();

        return new Response($this->twig->render('admin/gdpr/retention/index.html.twig', [
            'activeNav' => 'gdpr-retention',
            'policy' => [
                'ticketRetentionDays' => $policy?->getTicketRetentionDays() ?? 365,
                'logRetentionDays' => $policy?->getLogRetentionDays() ?? 90,
                'sessionRetentionDays' => $policy?->getSessionRetentionDays() ?? 30,
                'updatedAt' => $policy?->getUpdatedAt(),
                'updatedBy' => $admin->getEmail(),
            ],
            'saved' => (bool) $request->query->get('saved'),
        ]));
    }

    #[Route(path: '', name: 'admin_gdpr_retention_update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $policy = $this->retentionRepository->getCurrent();

        $ticketDays = max(1, (int) $request->request->get('ticket_retention_days', 365));
        $logDays = max(1, (int) $request->request->get('log_retention_days', 90));
        $sessionDays = max(1, (int) $request->request->get('session_retention_days', 30));

        $previous = $policy === null ? null : [
            'ticketRetentionDays' => $policy->getTicketRetentionDays(),
            'logRetentionDays' => $policy->getLogRetentionDays(),
            'sessionRetentionDays' => $policy->getSessionRetentionDays(),
        ];

        if ($policy === null) {
            $policy = new RetentionPolicy($ticketDays, $logDays, $sessionDays);
            $this->entityManager->persist($policy);
        } else {
            $policy->setTicketRetentionDays($ticketDays);
            $policy->setLogRetentionDays($logDays);
            $policy->setSessionRetentionDays($sessionDays);
            $this->entityManager->persist($policy);
        }

        $this->auditLogger->log($admin, 'gdpr.retention_updated', [
            'policy_id' => $policy->getId(),
            'previous' => $previous,
            'current' => [
                'ticketRetentionDays' => $ticketDays,
                'logRetentionDays' => $logDays,
                'sessionRetentionDays' => $sessionDays,
            ],
        ]);

        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/admin/gdpr/retention?saved=1']);
    }

    private function requireAdmin(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Forbidden.');
        }

        return $actor;
    }
}
