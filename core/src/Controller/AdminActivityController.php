<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\UserType;
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

        $entries = $this->auditLogRepository->findRecent(40);

        return new Response($this->twig->render('admin/activity/index.html.twig', [
            'activeNav' => 'activity',
            'entries' => $this->normalizeEntries($entries),
        ]));
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        return $actor instanceof User && $actor->getType() === UserType::Admin;
    }

    private function normalizeEntries(array $entries): array
    {
        return array_map(static function (\App\Entity\AuditLog $entry): array {
            return [
                'id' => $entry->getId(),
                'action' => $entry->getAction(),
                'actor' => $entry->getActor()?->getEmail() ?? 'System',
                'created_at' => $entry->getCreatedAt(),
                'payload' => $entry->getPayload(),
            ];
        }, $entries);
    }
}
