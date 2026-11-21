<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\ShopOrder;
use App\Module\Core\Domain\Entity\ShopRental;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\InstanceUpdatePolicy;
use App\Module\Core\Domain\Enum\InvoiceStatus;
use App\Module\Core\Domain\Enum\ShopOrderStatus;
use App\Module\Core\Domain\Enum\ShopOrderType;
use App\Module\Gameserver\Application\GameServerInstallPathManager;
use App\Module\Gameserver\Application\InstanceInstallService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ShopProvisioningService
{
    public function __construct(private DiskEnforcementService $disk, private \App\Module\Gameserver\Application\GameserverInstallDirectory $directories, private AuditLoggerInterface $audit, private EntityManagerInterface $em, private GameServerInstallPathManager $paths, private InstanceInstallService $installer)
    {
    }

    /** Payment and native installation are independent: failed preconditions remain retryable. */
    public function fulfill(ShopOrder $order): void
    {
        $this->em->wrapInTransaction(function () use ($order): void {
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            if (null !== $order->getContract() || ShopOrderStatus::Pending !== $order->getStatus() || [] === $order->getConfiguration()) {
                return;
            }
            $invoice = $order->getInvoice();
            if (null !== $invoice) {
                $this->em->refresh($invoice, LockMode::PESSIMISTIC_WRITE);
                if (InvoiceStatus::Paid !== $invoice->getStatus() || 0 !== $invoice->getOutstandingCents()) {
                    return;
                }
            } elseif (0 !== $order->getTotalPriceCents()) {
                return;
            }
            $config = $order->getConfiguration();
            if (ShopOrderType::Extend === $order->getType()) {
                $rental = $order->getRental();
                if (null === $rental) {
                    $order->attempted('RENTAL_MISSING');

                    return;
                }
                $this->em->refresh($rental, LockMode::PESSIMISTIC_WRITE);
                if (null === $invoice && ($config['automatic_renewal'] ?? false) === true && !$rental->isAutoRenew()) {
                    $order->setStatus(ShopOrderStatus::Cancelled);

                    return;
                }
                if ([] === $rental->getRenewalConfiguration()) {
                    $rental->configureSubscription($config, false);
                }
                $rental->extend($order->getMonths());
                if (InstanceStatus::Suspended === $rental->getInstance()->getStatus()) {
                    $rental->getInstance()->setStatus(InstanceStatus::Stopped);
                }
                $order->setStatus(ShopOrderStatus::Provisioned);
                $order->attempted();
                $this->audit->log($order->getCustomer(), 'shop.order.extended', ['order_id' => $order->getId(), 'rental_id' => $rental->getId()]);

                return;
            }
            $instance = $order->getInstance();
            if (null === $instance) {
                $node = $this->em->find(Agent::class, (string) $config['node_id']);
                $template = $this->em->find(Template::class, (int) $config['template_id']);
                if (null === $node || null === $template) {
                    $order->attempted('PROVISIONING_TARGET_MISSING');

                    return;
                }
                if (null !== $this->disk->guardNodeProvisioning($node, new \DateTimeImmutable())) {
                    $order->attempted('NODE_DISK_CAPACITY');

                    return;
                }
                $instance = new Instance($order->getCustomer(), $template, $node, (int) $config['cpu_percent'], (int) $config['ram_mib'], (int) $config['disk_mib'], null, InstanceStatus::PendingSetup, InstanceUpdatePolicy::Manual, initialSlots: (int) ($config['game_slots'] ?? Instance::DEFAULT_SLOTS));
                $instance->setInstanceBaseDir($this->directories->resolve($node, (string) ($config['instance_base_dir'] ?? '')));
                $this->em->persist($instance);
                $order->setInstance($instance);
                $this->em->flush();
                $this->paths->ensureInstallPath($instance);
                $rental = new ShopRental($order->getCustomer(), $order->getProduct(), $instance, \App\Module\Core\Domain\ValueObject\BillingPeriod::addMonths(new \DateTimeImmutable(), $order->getMonths()));
                $rental->configureSubscription($config, (bool) $config['subscription']);
                $this->em->persist($rental);
                $order->setRental($rental);
            } else {
                $this->em->refresh($instance, LockMode::PESSIMISTIC_WRITE);
            }
            if (in_array($instance->getStatus(), [InstanceStatus::Provisioning, InstanceStatus::Running, InstanceStatus::Stopped], true)) {
                $order->setStatus(ShopOrderStatus::Provisioned);
                $order->attempted();

                return;
            }
            if (InstanceStatus::Suspended === $instance->getStatus()) {
                $order->attempted('RENTAL_EXPIRED');

                return;
            }
            $install = $this->installer->prepareInstall($instance);
            if (!$install['ok']) {
                $order->attempted($install['error_code'] ?? 'INSTALL_PRECONDITION');

                return;
            }
            $payload = array_merge($install['payload'] ?? [], ['instance_id' => (string) $instance->getId(), 'customer_id' => (string) $order->getCustomer()->getId(), 'node_id' => $instance->getNode()->getId(), 'agent_id' => $instance->getNode()->getId(), 'cpu_limit' => (string) $instance->getCpuLimit(), 'ram_limit' => (string) ($install['payload']['ram_limit'] ?? $instance->getRamLimit()), 'disk_limit' => (string) $instance->getDiskLimit(), 'base_dir' => $instance->getInstanceBaseDir(), 'install_path' => $instance->getInstallPath()]);
            $job = new Job('sniper.install', $payload);
            $this->em->persist($job);
            $instance->setStatus(InstanceStatus::Provisioning);
            $order->setStatus(ShopOrderStatus::Provisioned);
            $order->attempted();
            $this->audit->log($order->getCustomer(), 'shop.install.queued', ['order_id' => $order->getId(), 'instance_id' => $instance->getId(), 'job_id' => $job->getId()]);
        });
    }
}
