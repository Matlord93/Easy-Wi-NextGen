<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Enum\UserType;
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

        $logs = $this->auditLogRepository->findBy([], ['id' => 'DESC'], 50);
        $total = $this->auditLogRepository->count([]);
        $latest = $logs[0] ?? null;

        return new Response($this->twig->render('admin/audit-logs/index.html.twig', [
            'activeNav' => 'audit-logs',
            'logs' => $this->normalizeLogs($logs),
            'summary' => [
                'total' => $total,
                'latest' => $latest?->getCreatedAt(),
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

        $logs = $this->auditLogRepository->findBy([], ['id' => 'DESC'], 50);

        return new Response($this->twig->render('admin/audit-logs/_table.html.twig', [
            'logs' => $this->normalizeLogs($logs),
        ]));
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        return $actor instanceof User && $actor->getType() === UserType::Admin;
    }

    private function normalizeLogs(array $logs): array
    {
        return array_map(static function (AuditLog $log): array {
            $actor = $log->getActor();
            $payload = json_encode(
                $log->getPayload(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );

            return [
                'id' => $log->getId(),
                'action' => $log->getAction(),
                'payload' => $payload === false ? '{}' : $payload,
                'createdAt' => $log->getCreatedAt(),
                'actor' => $actor?->getEmail() ?? 'System',
                'actorType' => $actor?->getType()->value,
                'hashPrev' => $log->getHashPrev(),
                'hashCurrent' => $log->getHashCurrent(),
            ];
        }, $logs);
    }
}
