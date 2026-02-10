<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\User;
use App\Repository\AuditLogRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/activity')]
final class AdminActivityController
{
    public function __construct(
        private readonly AuditLogRepository $auditLogRepository,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_activity', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $entries = $this->auditLogRepository->findRecentActivitySummaries(40);

        return new Response($this->twig->render('admin/activity/index.html.twig', [
            'activeNav' => 'activity',
            'entries' => $this->normalizeEntries($entries),
        ]));
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        return $actor instanceof User && $actor->isAdmin();
    }

    private function normalizeEntries(array $entries): array
    {
        return array_map(static function (array $entry): array {
            $payload = is_string($entry['payload_preview'] ?? null) ? $entry['payload_preview'] : '{}';

            return [
                'id' => (int) ($entry['id'] ?? 0),
                'action' => (string) ($entry['action'] ?? ''),
                'actor' => (string) ($entry['actor_email'] ?? 'System'),
                'created_at' => new \DateTimeImmutable((string) $entry['created_at']),
                'payload' => $payload,
            ];
        }, $entries);
    }
}
