<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\NotificationRepository;
use App\Service\AuditLogger;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/notifications')]
final class AdminNotificationController
{
    public function __construct(
        private readonly NotificationRepository $notificationRepository,
        private readonly NotificationService $notificationService,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_notifications', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $actor = $this->requireAdmin($request);

        $this->notificationService->syncAdminPaymentNotifications();
        $this->entityManager->flush();

        $notifications = $this->notificationRepository->findLatestForRecipient($actor, 40);

        return new Response($this->twig->render('admin/notifications/index.html.twig', [
            'activeNav' => 'notifications',
            'notifications' => $this->normalizeNotifications($notifications),
        ]));
    }

    #[Route(path: '/{id}/read', name: 'admin_notifications_read', methods: ['POST'])]
    public function markRead(Notification $notification, Request $request): Response
    {
        $actor = $this->requireAdmin($request);
        if ($notification->getRecipient()->getId() !== $actor->getId()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        if (!$notification->isRead()) {
            $notification->markRead();
            $this->auditLogger->log($actor, 'notification.read', [
                'notification_id' => $notification->getId(),
                'recipient_id' => $notification->getRecipient()->getId(),
            ]);
            $this->entityManager->flush();
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function requireAdmin(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Forbidden.');
        }

        return $actor;
    }

    private function normalizeNotifications(array $notifications): array
    {
        return array_map(static function (Notification $notification): array {
            return [
                'id' => $notification->getId(),
                'title' => $notification->getTitle(),
                'body' => $notification->getBody(),
                'category' => $notification->getCategory(),
                'action_url' => $notification->getActionUrl(),
                'created_at' => $notification->getCreatedAt(),
                'read_at' => $notification->getReadAt(),
            ];
        }, $notifications);
    }
}
