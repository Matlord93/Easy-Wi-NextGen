<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\ShopOrder;
use App\Module\Core\Domain\Entity\ShopProduct;
use App\Module\Core\Domain\Entity\ShopRental;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\InstanceUpdatePolicy;
use App\Module\Core\Domain\Enum\ShopOrderStatus;
use App\Module\Core\Domain\Enum\ShopOrderType;
use Doctrine\ORM\EntityManagerInterface;

final class ShopProvisioningService
{
    public function __construct(
        private readonly DiskEnforcementService $diskEnforcementService,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function provision(User $customer, ShopProduct $product, int $months): ShopRental
    {
        $months = max(1, $months);

        $blockMessage = $this->diskEnforcementService->guardNodeProvisioning(
            $product->getNode(),
            new \DateTimeImmutable(),
        );
        if ($blockMessage !== null) {
            throw new \RuntimeException($blockMessage);
        }

        $instance = new Instance(
            $customer,
            $product->getTemplate(),
            $product->getNode(),
            $product->getCpuLimit(),
            $product->getRamLimit(),
            $product->getDiskLimit(),
            null,
            InstanceStatus::PendingSetup,
            InstanceUpdatePolicy::Manual,
        );

        $this->entityManager->persist($instance);

        $expiresAt = (new \DateTimeImmutable())->modify(sprintf('+%d months', $months));
        $rental = new ShopRental($customer, $product, $instance, $expiresAt);
        $this->entityManager->persist($rental);

        $order = new ShopOrder(
            $customer,
            $product,
            ShopOrderType::New,
            ShopOrderStatus::Provisioned,
            $months,
            $product->getPriceMonthlyCents(),
            $product->getPriceMonthlyCents() * $months,
            $instance,
        );
        $this->entityManager->persist($order);

        $this->auditLogger->log($customer, 'shop.order.provisioned', [
            'customer_id' => $customer->getId(),
            'instance_id' => $instance->getId(),
            'product_id' => $product->getId(),
            'months' => $months,
        ]);

        $this->entityManager->flush();

        return $rental;
    }

    public function extendRental(ShopRental $rental, int $months): ShopOrder
    {
        $months = max(1, $months);

        $rental->extend($months);
        $this->entityManager->persist($rental);

        $order = new ShopOrder(
            $rental->getCustomer(),
            $rental->getProduct(),
            ShopOrderType::Extend,
            ShopOrderStatus::Provisioned,
            $months,
            $rental->getProduct()->getPriceMonthlyCents(),
            $rental->getProduct()->getPriceMonthlyCents() * $months,
            $rental->getInstance(),
        );
        $this->entityManager->persist($order);

        $this->auditLogger->log($rental->getCustomer(), 'shop.order.extended', [
            'customer_id' => $rental->getCustomer()->getId(),
            'instance_id' => $rental->getInstance()->getId(),
            'product_id' => $rental->getProduct()->getId(),
            'months' => $months,
        ]);

        $this->entityManager->flush();

        return $order;
    }
}
