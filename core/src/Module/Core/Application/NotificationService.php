<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Notification;
use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InvoiceStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\InvoiceRepository;
use App\Repository\EntityQueryResult;
use App\Repository\NotificationRepository;
use App\Repository\NotificationPreferenceRepository;
use App\Repository\UserRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class NotificationService
{
    public function __construct(
        private readonly NotificationRepository $notificationRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationPreferenceRepository $preferenceRepository,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function notify(User $recipient, string $eventKey, string $title, string $body, string $category, ?string $actionUrl = null): ?Notification
    {
        $preference = $this->preferenceRepository->findFor($recipient, $category);
        if ($preference !== null && !$preference->isInAppEnabled()) {
            return null;
        }
        if ($actionUrl !== null && (!str_starts_with($actionUrl, '/') || str_starts_with($actionUrl, '//'))) {
            throw new \InvalidArgumentException('Notification action URLs must be local absolute paths.');
        }
        if ($this->notificationRepository->findOneByEventKey($recipient, $eventKey) !== null) {
            return null;
        }

        $notification = new Notification($recipient, $category, $title, $body, $eventKey, $actionUrl);
        $this->entityManager->persist($notification);

        return $notification;
    }

    public function notifyAdmins(string $eventKey, string $title, string $body, string $category, ?string $actionUrl = null): void
    {
        $admins = EntityQueryResult::all($this->userRepository->createQueryBuilder('user')
            ->andWhere('user.type IN (:types)')
            ->setParameter('types', [UserType::Admin->value, UserType::Superadmin->value])
            ->getQuery(), User::class);
        $created = 0;
        foreach ($admins as $admin) {
            if ($this->notify($admin, $eventKey, $title, $body, $category, $actionUrl) !== null) {
                ++$created;
            }
        }
        $this->logger->debug('notification.admin_batch_completed', ['event_key' => $eventKey, 'recipients' => count($admins), 'created' => $created]);
    }

    public function syncAdminPaymentNotifications(): void
    {
        $pastDueInvoices = EntityQueryResult::all($this->invoiceRepository->createQueryBuilder('invoice')
            ->andWhere('invoice.status IN (:statuses)')
            ->setParameter('statuses', [InvoiceStatus::Open->value, InvoiceStatus::PastDue->value])
            ->orderBy('invoice.dueDate', 'ASC')
            ->setMaxResults(15)
            ->getQuery(), Invoice::class);

        foreach ($pastDueInvoices as $invoice) {
            $eventKey = sprintf('invoice.due.%s', (string) $invoice->getId());
            $title = sprintf('Payment due · #%s', $invoice->getNumber());
            $body = sprintf('%s · %s %s', $invoice->getCustomer()->getEmail(), $invoice->getAmountDueCents() / 100, $invoice->getCurrency());
            $this->notifyAdmins($eventKey, $title, $body, 'billing', '/admin/billing');
        }
    }
}
