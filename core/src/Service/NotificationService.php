<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\InvoiceStatus;
use App\Enum\UserType;
use App\Repository\InvoiceRepository;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

final class NotificationService
{
    public function __construct(
        private readonly NotificationRepository $notificationRepository,
        private readonly UserRepository $userRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function notify(User $recipient, string $eventKey, string $title, string $body, string $category, ?string $actionUrl = null): ?Notification
    {
        if ($this->notificationRepository->findOneByEventKey($recipient, $eventKey) !== null) {
            return null;
        }

        $notification = new Notification($recipient, $category, $title, $body, $eventKey, $actionUrl);
        $this->entityManager->persist($notification);

        return $notification;
    }

    public function notifyAdmins(string $eventKey, string $title, string $body, string $category, ?string $actionUrl = null): void
    {
        $admins = $this->userRepository->findBy(['type' => UserType::Admin->value]);
        foreach ($admins as $admin) {
            $this->notify($admin, $eventKey, $title, $body, $category, $actionUrl);
        }
    }

    public function syncAdminPaymentNotifications(): void
    {
        $pastDueInvoices = $this->invoiceRepository->createQueryBuilder('invoice')
            ->andWhere('invoice.status IN (:statuses)')
            ->setParameter('statuses', [InvoiceStatus::Open, InvoiceStatus::PastDue])
            ->orderBy('invoice.dueDate', 'ASC')
            ->setMaxResults(15)
            ->getQuery()
            ->getResult();

        foreach ($pastDueInvoices as $invoice) {
            $eventKey = sprintf('invoice.due.%s', $invoice->getId());
            $title = sprintf('Payment due · #%s', $invoice->getNumber());
            $body = sprintf('%s · %s %s', $invoice->getCustomer()->getEmail(), $invoice->getAmountDueCents() / 100, $invoice->getCurrency());
            $this->notifyAdmins($eventKey, $title, $body, 'billing', '/admin/billing');
        }
    }
}
