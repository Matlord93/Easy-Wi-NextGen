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

#[Route(path: '/activity')]
final class CustomerActivityController
{
    public function __construct(
        private readonly AuditLogRepository $auditLogRepository,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_activity', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $entries = $this->auditLogRepository->findRecent(40, $customer);

        return new Response($this->twig->render('customer/activity/index.html.twig', [
            'activeNav' => 'activity',
            'entries' => $this->normalizeEntries($entries),
        ]));
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Forbidden.');
        }

        return $actor;
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
