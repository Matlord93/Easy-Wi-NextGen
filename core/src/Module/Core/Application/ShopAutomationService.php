<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Application\Billing\PaymentSettings;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\ShopOrder;
use App\Module\Core\Domain\Entity\ShopRental;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\InvoiceStatus;
use App\Module\Core\Domain\Enum\ShopOrderStatus;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class ShopAutomationService
{
    public function __construct(private EntityManagerInterface $em, private ShopCheckoutService $checkout, private ShopProvisioningService $provisioning, private PaymentSettings $settings, private AuditLoggerInterface $audit)
    {
    }

    public function run(): int
    {
        $this->settings->reset();
        $now = new \DateTimeImmutable();
        $this->expireUnpaidCheckouts($now);
        $count = 0;
        /** @var list<ShopOrder> $orders */
        $orders = $this->em->getRepository(ShopOrder::class)->createQueryBuilder('o')->leftJoin('o.invoice', 'i')->leftJoin('o.contract', 'contract')->where('o.status = :pending AND o.configuration IS NOT NULL')->andWhere('contract.id IS NULL')->andWhere('(i.status = :paid OR i.status = :cancelled OR o.totalPriceCents = 0)')->andWhere('o.lastAttemptAt IS NULL OR o.lastAttemptAt < :retry')->setParameter('pending', ShopOrderStatus::Pending)->setParameter('paid', InvoiceStatus::Paid)->setParameter('cancelled', InvoiceStatus::Cancelled)->setParameter('retry', $now->modify('-5 minutes'))->orderBy('o.id')->setMaxResults(100)->getQuery()->getResult();
        foreach ($orders as $order) {
            if (!$order instanceof ShopOrder) {
                continue;
            }
            if (InvoiceStatus::Cancelled === $order->getInvoice()?->getStatus()) {
                $this->em->wrapInTransaction(function () use ($order): void {
                    $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
                    if (ShopOrderStatus::Pending !== $order->getStatus()) {
                        return;
                    }
                    $coupon = $order->getCoupon();
                    if (null !== $coupon) {
                        $this->em->refresh($coupon, LockMode::PESSIMISTIC_WRITE);
                        $coupon->releaseReservation();
                        $order->releaseCoupon();
                    }
                    $order->setStatus(ShopOrderStatus::Cancelled);
                });
            } else {
                $this->provisioning->fulfill($order);
            }
            ++$count;
        }
        /** @var list<ShopRental> $rentals */
        $rentals = $this->em->getRepository(ShopRental::class)->createQueryBuilder('r')->where('r.autoRenew = true AND r.expiresAt <= :horizon')->setParameter('horizon', $now->modify('+7 days'))->orderBy('r.expiresAt')->setMaxResults(100)->getQuery()->getResult();
        foreach ($rentals as $rental) {
            if (!$rental instanceof ShopRental) {
                continue;
            }
            $nonce = substr(hash('sha256', 'renewal:'.($rental->getId() ?? 0).':'.$rental->getExpiresAt()->format(DATE_ATOM)), 0, 32);
            $request = Request::create($this->settings->publicSettings()['base_url'].'/marketplace');
            $this->checkout->extend($rental, (int) ($rental->getRenewalConfiguration()['months'] ?? 1), $nonce, $request, true);
        }
        // Existing historical rentals are untouched until they use the new paid checkout.
        /** @var list<ShopRental> $expired */
        $expired = $this->em->getRepository(ShopRental::class)->createQueryBuilder('r')->join('r.instance', 'i')->where('r.expiresAt <= :now AND r.renewalConfiguration IS NOT NULL AND i.status <> :suspended')->setParameter('now', $now, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)->setParameter('suspended', InstanceStatus::Suspended)->setMaxResults(100)->getQuery()->getResult();
        foreach ($expired as $rental) {
            if (!$rental instanceof ShopRental) {
                continue;
            }
            $this->em->wrapInTransaction(function () use ($rental, $now): void {
                $this->em->refresh($rental, LockMode::PESSIMISTIC_WRITE);
                $instance = $rental->getInstance();
                if ($rental->getExpiresAt() > $now || InstanceStatus::Suspended === $instance->getStatus()) {
                    return;
                }
                $this->em->persist(new Job('instance.stop', ['instance_id' => (string) $instance->getId(), 'customer_id' => (string) $rental->getCustomer()->getId(), 'node_id' => $instance->getNode()->getId(), 'agent_id' => $instance->getNode()->getId(), 'action' => 'stop']));
                $instance->setStatus(InstanceStatus::Suspended);
                $this->audit->log($rental->getCustomer(), 'shop.rental.expired', ['rental_id' => $rental->getId(), 'instance_id' => $instance->getId()]);
            });
        }

        return $count;
    }

    private function expireUnpaidCheckouts(\DateTimeImmutable $now): void
    {
        /** @var list<ShopOrder> $orders */
        $orders = $this->em->getRepository(ShopOrder::class)->createQueryBuilder('o')
            ->join('o.invoice', 'i')->leftJoin('o.contract', 'contract')->where('o.status = :pending AND o.configuration IS NOT NULL AND o.createdAt < :expiry')->andWhere('contract.id IS NULL')
            ->andWhere('i.status IN (:unpaid)')->setParameter('pending', ShopOrderStatus::Pending)
            ->setParameter('unpaid', [InvoiceStatus::Open, InvoiceStatus::PastDue])->setParameter('expiry', $now->modify('-14 days'), \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->orderBy('o.id')->setMaxResults(100)->getQuery()->getResult();
        foreach ($orders as $order) {
            $this->em->wrapInTransaction(function () use ($order, $now): void {
                $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
                $invoice = $order->getInvoice();
                if (null === $invoice || ShopOrderStatus::Pending !== $order->getStatus()) {
                    return;
                }
                $this->em->refresh($invoice, LockMode::PESSIMISTIC_WRITE);
                $inFlight = (int) $this->em->getRepository(\App\Module\Core\Domain\Entity\PaymentCheckout::class)->createQueryBuilder('c')->select('COUNT(c.id)')
                    ->where('c.invoice = :invoice AND c.completed = false AND c.createdAt >= :expiry')->setParameter('invoice', \App\Infrastructure\Doctrine\EntityIdentifier::require($invoice->getId()), \Doctrine\DBAL\Types\Types::INTEGER)
                    ->setParameter('expiry', $now->modify('-14 days'), \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)->getQuery()->getSingleScalarResult();
                if (0 === $invoice->getPaidAmountCents() && 0 === $inFlight && in_array($invoice->getStatus(), [InvoiceStatus::Open, InvoiceStatus::PastDue], true)) {
                    $invoice->cancel('Unpaid shop checkout expired after 14 days.');
                    $this->audit->log($order->getCustomer(), 'shop.checkout.expired', ['order_id' => $order->getId(), 'invoice_id' => $invoice->getId()]);
                }
            });
        }
    }
}
