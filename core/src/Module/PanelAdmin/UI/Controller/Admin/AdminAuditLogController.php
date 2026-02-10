<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\User;
use App\Repository\AuditLogRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/audit-logs')]
final class AdminAuditLogController
{
    public function __construct(
        private readonly AuditLogRepository $auditLogRepository,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_audit_logs', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $logs = $this->auditLogRepository->findRecentSummaries(50);
        $total = $this->auditLogRepository->count([]);
        $latest = $logs[0]['created_at'] ?? null;

        return new Response($this->twig->render('admin/audit-logs/index.html.twig', [
            'activeNav' => 'audit-logs',
            'logs' => $this->normalizeLogs($logs),
            'summary' => [
                'total' => $total,
                'latest' => is_string($latest) ? new \DateTimeImmutable($latest) : null,
                'showing' => count($logs),
            ],
        ]));
    }

    #[Route(path: '/table', name: 'admin_audit_logs_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $logs = $this->auditLogRepository->findRecentSummaries(50);

        return new Response($this->twig->render('admin/audit-logs/_table.html.twig', [
            'logs' => $this->normalizeLogs($logs),
        ]));
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        return $actor instanceof User && $actor->isAdmin();
    }

    private function normalizeLogs(array $logs): array
    {
        return array_map(static function (array $log): array {
            $payload = is_string($log['payload_preview'] ?? null) ? $log['payload_preview'] : '{}';

            return [
                'id' => (int) ($log['id'] ?? 0),
                'action' => (string) ($log['action'] ?? ''),
                'payload' => $payload,
                'createdAt' => new \DateTimeImmutable((string) $log['created_at']),
                'actor' => (string) ($log['actor_email'] ?? 'System'),
                'actorType' => $log['actor_type'] ?? null,
                'hashPrev' => $log['hash_prev'] ?? null,
                'hashCurrent' => (string) ($log['hash_current'] ?? ''),
            ];
        }, $logs);
    }
}
