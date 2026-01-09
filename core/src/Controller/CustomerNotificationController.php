<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\NotificationRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/notifications')]
final class CustomerNotificationController
{
    public function __construct(
        private readonly NotificationRepository $notificationRepository,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_notifications', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $notifications = $this->notificationRepository->findLatestForRecipient($customer, 40);

        return new Response($this->twig->render('customer/notifications/index.html.twig', [
            'activeNav' => 'notifications',
            'notifications' => $this->normalizeNotifications($notifications),
        ]));
    }

    #[Route(path: '/{id}/read', name: 'customer_notifications_read', methods: ['POST'])]
    public function markRead(Notification $notification, Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        if ($notification->getRecipient()->getId() !== $customer->getId()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        if (!$notification->isRead()) {
            $notification->markRead();
            $this->auditLogger->log($customer, 'notification.read', [
                'notification_id' => $notification->getId(),
                'recipient_id' => $notification->getRecipient()->getId(),
            ]);
            $this->entityManager->flush();
        }

        return new Response($this->twig->render('customer/notifications/_item_with_badge.html.twig', [
            'notification' => $this->normalizeNotification($notification),
            'unreadCount' => $this->notificationRepository->findUnreadCount($customer),
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

    private function normalizeNotifications(array $notifications): array
    {
        return array_map([$this, 'normalizeNotification'], $notifications);
    }

    private function normalizeNotification(Notification $notification): array
    {
        return [
            'id' => $notification->getId(),
            'title' => $notification->getTitle(),
            'body' => $notification->getBody(),
            'category' => $notification->getCategory(),
            'action_url' => $notification->getActionUrl(),
            'created_at' => $notification->getCreatedAt(),
            'read_at' => $notification->getReadAt(),
        ];
    }
}
