<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\User;
use App\Repository\AuditLogRepository;
use App\Module\Core\Application\AuditLogCsvExporter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(path: '/admin/audit-logs')]
final class AdminAuditLogController
{
    public function __construct(
        private readonly AuditLogRepository $auditLogRepository,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly AuditLogCsvExporter $csvExporter,
    ) {
    }

    #[Route(path: '', name: 'admin_audit_logs', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $filters = $this->filters($request);
        $logs = $this->auditLogRepository->searchSummaries($filters['action'], $filters['actor'], $filters['since'], 50);
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
            'filters' => $filters,
        ]));
    }

    #[Route(path: '/table', name: 'admin_audit_logs_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $filters = $this->filters($request);
        $logs = $this->auditLogRepository->searchSummaries($filters['action'], $filters['actor'], $filters['since'], 50);

        return new Response($this->twig->render('admin/audit-logs/_table.html.twig', [
            'logs' => $this->normalizeLogs($logs),
        ]));
    }

    #[Route(path: '/export.csv', name: 'admin_audit_logs_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }
        $filters = $this->filters($request);
        $rows = $this->auditLogRepository->searchSummaries($filters['action'], $filters['actor'], $filters['since'], 10000);
        return new Response($this->csvExporter->export($rows), Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="audit-logs-'.gmdate('Y-m-d').'.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{action: ?string, actor: ?string, since: ?\DateTimeImmutable} */
    private function filters(Request $request): array
    {
        $action = trim((string) $request->query->get('action', '')) ?: null;
        $actor = trim((string) $request->query->get('actor', '')) ?: null;
        $since = null;
        $sinceRaw = trim((string) $request->query->get('since', ''));
        if ('' !== $sinceRaw) {
            try { $since = new \DateTimeImmutable($sinceRaw); } catch (\Exception) { $since = null; }
        }
        return ['action' => $action, 'actor' => $actor, 'since' => $since];
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
