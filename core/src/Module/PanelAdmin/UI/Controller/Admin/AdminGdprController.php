<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Application\GdprDataInventoryMap;
use App\Module\Core\Domain\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\RetentionPolicyRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/gdpr')]
final class AdminGdprController
{
    public function __construct(
        private readonly GdprDataInventoryMap $inventoryMap,
        private readonly RetentionPolicyRepository $retentionRepository,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_gdpr_overview', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requireAdmin($request);
        $policy = $this->retentionRepository->getCurrent();
        $auditEntries = $this->auditLogRepository->findRecentByActions([
            'gdpr.export_requested',
            'gdpr.export_ready',
            'gdpr.export_failed',
            'gdpr.export_deleted',
            'gdpr.deletion_requested',
            'gdpr.user_anonymized',
        ], 40);

        return new Response($this->twig->render('admin/gdpr/index.html.twig', [
            'activeNav' => 'gdpr-overview',
            'inventory' => $this->inventoryMap->all(),
            'policy' => [
                'ticketRetentionDays' => $policy?->getTicketRetentionDays() ?? 365,
                'logRetentionDays' => $policy?->getLogRetentionDays() ?? 7,
                'sessionRetentionDays' => $policy?->getSessionRetentionDays() ?? 30,
            ],
            'auditEntries' => $auditEntries,
        ]));
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
