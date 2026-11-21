<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Application\Billing\BillingSettings;
use App\Module\Core\Application\Billing\InvoiceArchiveManager;
use App\Module\Core\Application\Billing\InvoiceDocumentService;
use App\Module\Core\Application\Billing\InvoiceItems;
use App\Module\Core\Application\Billing\InvoiceLayoutRenderer;
use App\Module\Core\Application\Billing\PaymentSettings;
use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\ShopCoupon;
use App\Module\Core\Domain\Entity\ShopOrder;
use App\Module\Core\Domain\Entity\ShopProduct;
use App\Module\Core\Domain\Entity\ShopRental;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InvoiceStatus;
use App\Module\Core\Domain\Enum\ShopOrderStatus;
use App\Module\Core\Domain\Enum\ShopOrderType;
use App\Repository\ShopCouponRepository;
use App\Repository\ShopOrderRepository;
use App\Repository\ShopProductVariantRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class ShopCheckoutService
{
    public function __construct(private EntityManagerInterface $em, private ShopProductVariantRepository $variants, private ShopCouponRepository $coupons, private ShopOrderRepository $orders, private PaymentSettings $payments, private BillingSettings $billing, private InvoiceLayoutRenderer $layouts, private InvoiceDocumentService $documents, private InvoiceArchiveManager $archives, private AuditLoggerInterface $audit)
    {
    }

    /** @return array<string, int|string|bool> */
    public function quote(ShopProduct $product, int $months, int $variantId, string $code, bool $subscription): array
    {
        if (\App\Module\Core\Domain\Enum\ShopProductKind::Gameserver !== $product->getKind()) {
            throw new \InvalidArgumentException('Please use the rental contract checkout for this product.');
        }
        if (!$product->isActive() || !$product->isCustomerActive() || !$product->getCategory()->isActive() || $months < 1 || $months > 36 || ($subscription && !$product->isSubscriptionEnabled())) {
            throw new \InvalidArgumentException('Product or billing period is unavailable.');
        }
        $variant = $variantId > 0 ? $this->variants->find($variantId) : null;
        if ($variantId > 0 && (null === $variant || !$variant->isActive() || $variant->getProduct()->getId() !== $product->getId())) {
            throw new \InvalidArgumentException('Variant is unavailable.');
        }
        $price = $variant?->getPriceMonthlyCents() ?? $product->getPriceMonthlyCents();
        if ($price < 0 || $price > intdiv(2_147_483_647, $months)) {
            throw new \InvalidArgumentException('Invalid product price.');
        }
        $cpu = $variant?->getCpuLimit() ?? $product->getCpuLimit();
        $ram = $variant?->getRamLimit() ?? $product->getRamLimit();
        $disk = $variant?->getDiskLimit() ?? $product->getDiskLimit();
        if ($cpu < 1 || $cpu > 21_474_836 || $ram < 1 || $ram > 2_097_151 || $disk < 1 || $disk > 2_097_151) {
            throw new \InvalidArgumentException('Invalid product resource limits.');
        }
        $subtotal = $price * $months;
        $discount = 0;
        $code = strtoupper(trim($code));
        if ('' !== $code) {
            $coupon = $this->coupons->findOneBy(['siteId' => $product->getSiteId(), 'code' => $code]);
            $discount = $coupon?->discountFor($subtotal, new \DateTimeImmutable()) ?? 0;
            if ($discount < 1) {
                throw new \InvalidArgumentException('Coupon is invalid, exhausted or the minimum order amount was not reached.');
            }
        }

        return ['name' => $product->getName(), 'variant' => $variant?->getName() ?? '', 'variant_id' => $variantId, 'months' => $months, 'monthly_cents' => $price, 'subtotal_cents' => $subtotal, 'discount_cents' => $discount, 'total_cents' => $subtotal - $discount, 'coupon' => $code, 'currency' => $this->payments->publicSettings()['currency'], 'subscription' => $subscription,
            'cpu' => $cpu, 'ram' => $ram, 'disk' => $disk, 'cpu_percent' => $cpu * 100, 'ram_mib' => $ram * 1024, 'disk_mib' => $disk * 1024, 'game_slots' => $variant?->getSlots() ?? $product->getGameserverSlots(), 'template_id' => $product->getTemplate()->getId() ?? 0, 'node_id' => $product->getNode()->getId()];
    }

    /** @param array<string, int|string|bool> $quote */
    public static function digest(array $quote): string
    {
        return hash('sha256', json_encode($quote, JSON_THROW_ON_ERROR));
    }

    public function create(User $customer, ShopProduct $product, int $months, int $variantId, string $code, bool $subscription, string $nonce, string $digest, Request $request): ShopOrder
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $nonce)) {
            throw new \InvalidArgumentException('Invalid checkout reference.');
        }
        $key = hash('sha256', ($customer->getId() ?? 0).':purchase:'.($product->getId() ?? 0).':'.$nonce);

        return $this->em->wrapInTransaction(function () use ($customer, $product, $months, $variantId, $code, $subscription, $key, $digest, $request): ShopOrder {
            // Serialize checkouts per customer. Retried POSTs return the original order.
            $this->em->lock($customer, LockMode::PESSIMISTIC_WRITE);
            $existing = $this->orders->findOneBy(['requestKey' => $key, 'customer' => $customer]);
            if (null !== $existing) {
                return $existing;
            }
            $this->em->refresh($product, LockMode::PESSIMISTIC_READ);
            $coupon = '' !== strtoupper(trim($code)) ? $this->coupons->findOneBy(['siteId' => $product->getSiteId(), 'code' => strtoupper(trim($code))]) : null;
            if (null !== $coupon) {
                $this->em->refresh($coupon, LockMode::PESSIMISTIC_WRITE);
            }
            $quote = $this->quote($product, $months, $variantId, $code, $subscription);
            if (!hash_equals(self::digest($quote), $digest)) {
                throw new \InvalidArgumentException('The price or product changed. Please review the checkout again.');
            }
            if (null !== $coupon) {
                $coupon->redeem(new \DateTimeImmutable());
            }

            return $this->issue($customer, $product, $quote, $key, $request, $coupon);
        });
    }

    public function extend(ShopRental $rental, int $months, string $nonce, Request $request, bool $automatic = false): ShopOrder
    {
        if ($months < 1 || $months > 36 || !preg_match('/^[a-f0-9]{32}$/D', $nonce)) {
            throw new \InvalidArgumentException('Invalid billing period or reference.');
        }

        return $this->em->wrapInTransaction(function () use ($rental, $months, $nonce, $request, $automatic): ShopOrder {
            $this->em->refresh($rental, LockMode::PESSIMISTIC_WRITE);
            if ($automatic && !$rental->isAutoRenew()) {
                throw new \DomainException('Subscription was cancelled.');
            }
            $key = hash('sha256', ($rental->getCustomer()->getId() ?? 0).':extend:'.($rental->getId() ?? 0).':'.$nonce);
            $existing = $this->orders->findOneBy(['requestKey' => $key, 'customer' => $rental->getCustomer()]) ?? $this->orders->findOneBy(['rental' => $rental, 'status' => ShopOrderStatus::Pending]);
            if (null !== $existing) {
                return $existing;
            }
            $quote = $rental->getRenewalConfiguration();
            if ([] === $quote) {
                $product = $rental->getProduct();
                $instance = $rental->getInstance();
                $quote = ['name' => $product->getName(), 'variant' => '', 'variant_id' => 0, 'months' => $months, 'monthly_cents' => $product->getPriceMonthlyCents(), 'subtotal_cents' => 0, 'total_cents' => 0, 'discount_cents' => 0, 'coupon' => '', 'currency' => $this->payments->publicSettings()['currency'], 'subscription' => false, 'cpu' => $instance->getCpuLimit(), 'ram' => $instance->getRamLimit(), 'disk' => $instance->getDiskLimit(), 'cpu_percent' => $instance->getCpuLimit(), 'ram_mib' => $instance->getRamLimit(), 'disk_mib' => $instance->getDiskLimit(), 'template_id' => $instance->getTemplate()->getId() ?? 0, 'node_id' => $instance->getNode()->getId()];
            }
            $price = (int) $quote['monthly_cents'];
            if ($price < 0 || $price > intdiv(2_147_483_647, $months)) {
                throw new \DomainException('Invalid renewal price.');
            }
            $quote['months'] = $months;
            $quote['subtotal_cents'] = $quote['total_cents'] = $price * $months;
            $quote['discount_cents'] = 0;
            $quote['coupon'] = '';

            return $this->issue($rental->getCustomer(), $rental->getProduct(), $quote, $key, $request, null, $rental, $automatic ? $rental->getExpiresAt() : null);
        });
    }

    public function cancelSubscription(ShopRental $rental): void
    {
        $this->em->wrapInTransaction(function () use ($rental): void {
            $this->em->refresh($rental, LockMode::PESSIMISTIC_WRITE);
            $rental->cancelSubscription();
            $this->audit->log($rental->getCustomer(), 'shop.subscription.cancelled', ['rental_id' => $rental->getId(), 'paid_until' => $rental->getExpiresAt()->format(DATE_ATOM)]);
        });
        foreach ($this->orders->findBy(['rental' => $rental, 'status' => ShopOrderStatus::Pending]) as $order) {
            $this->em->wrapInTransaction(function () use ($order): void {
                $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
                $invoice = $order->getInvoice();
                if (ShopOrderStatus::Pending !== $order->getStatus() || ($order->getConfiguration()['automatic_renewal'] ?? false) !== true) {
                    return;
                }
                if (null === $invoice) {
                    $order->setStatus(ShopOrderStatus::Cancelled);

                    return;
                }
                $this->em->refresh($invoice, LockMode::PESSIMISTIC_WRITE);
                // A payment already initiated at a provider can still settle asynchronously.
                $initiated = $this->em->getRepository(\App\Module\Core\Domain\Entity\PaymentCheckout::class)->count(['invoice' => $invoice]);
                if (0 === $initiated && 0 === $invoice->getPaidAmountCents() && !in_array($invoice->getStatus(), [InvoiceStatus::Paid, InvoiceStatus::Cancelled], true)) {
                    $invoice->cancel('Subscription cancelled before renewal.');
                    $order->setStatus(ShopOrderStatus::Cancelled);
                }
            });
        }
    }

    /** @param array<string, int|string|bool> $quote */
    private function issue(User $customer, ShopProduct $product, array $quote, string $key, Request $request, ?ShopCoupon $coupon = null, ?ShopRental $rental = null, ?\DateTimeImmutable $renewalFor = null): ShopOrder
    {
        $invoice = null;
        $total = (int) $quote['total_cents'];
        $quote['automatic_renewal'] = null !== $renewalFor;
        $description = (string) $quote['name'].('' !== (string) $quote['variant'] ? ' · '.(string) $quote['variant'] : '').' · '.(int) $quote['months'].' Monate';
        if ($total > 0) {
            $billing = $this->billing->get();
            $tax = InvoiceItems::calculate([['description' => $description.((int) $quote['discount_cents'] > 0 ? ' (Gutschein '.(string) $quote['coupon'].')' : ''), 'quantity' => '1', 'unit_price' => intdiv($total, 100).'.'.str_pad((string) ($total % 100), 2, '0', STR_PAD_LEFT), 'tax_rate' => (string) $billing['tax_rate']]], (string) $billing['tax_mode']);
            $number = 'SHOP-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(10)));
            $invoice = new Invoice($customer, $number, $total, (string) $quote['currency'], $renewalFor ?? new \DateTimeImmutable('+7 days'));
            $this->layouts->snapshot($invoice, $request, $tax, $description);
            $this->em->persist($invoice);
        }
        $order = new ShopOrder($customer, $product, null !== $rental ? ShopOrderType::Extend : ShopOrderType::New, ShopOrderStatus::Pending, (int) $quote['months'], (int) $quote['monthly_cents'], $total, $rental?->getInstance());
        $order->checkout($invoice, $quote, $key, $coupon, $rental, $renewalFor);
        $this->em->persist($order);
        $this->em->flush();
        if (null !== $invoice) {
            $this->archives->archiveInvoiceContent($invoice, $this->documents->generate($invoice, $request), $invoice->getNumber().'.pdf', 'application/pdf', $customer);
        }
        $this->audit->log($customer, 'shop.order.created', ['order_id' => $order->getId(), 'invoice_id' => $invoice?->getId(), 'total_cents' => $total]);

        return $order;
    }
}
