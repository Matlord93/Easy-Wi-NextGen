<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Module\Core\Domain\Enum\ShopOrderStatus;
use App\Module\Core\Domain\Enum\ShopOrderType;
use App\Repository\ShopOrderRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Doctrine hydrates and proxies this entity; Twig also calls its public accessors.
 *
 * @psalm-api
 */
#[ORM\Entity(repositoryClass: ShopOrderRepository::class)]
#[ORM\Table(name: 'shop_orders')]
#[ORM\UniqueConstraint(name: 'uniq_shop_order_request', columns: ['request_key'])]
#[ORM\UniqueConstraint(name: 'uniq_shop_order_renewal', columns: ['rental_id', 'renewal_for'])]
#[ORM\Index(name: 'idx_shop_order_processing', columns: ['status', 'created_at'])]
class ShopOrder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $customer;

    #[ORM\ManyToOne(targetEntity: ShopProduct::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ShopProduct $product;

    #[ORM\ManyToOne(targetEntity: Instance::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Instance $instance = null;

    #[ORM\Column(enumType: ShopOrderType::class)]
    private ShopOrderType $type;

    #[ORM\Column(enumType: ShopOrderStatus::class)]
    private ShopOrderStatus $status;

    #[ORM\Column]
    private int $months;

    #[ORM\Column]
    private int $unitPriceCents;

    #[ORM\Column]
    private int $totalPriceCents;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\OneToOne(mappedBy: 'order', targetEntity: ShopContract::class)] private ?ShopContract $contract = null;
    public function getContract(): ?ShopContract
    {
        return $this->contract;
    }

    public function setContract(ShopContract $contract): void
    {
        $this->contract = $contract;
    }

    #[ORM\ManyToOne(targetEntity: Invoice::class), ORM\JoinColumn(nullable: true)] private ?Invoice $invoice = null;
    #[ORM\ManyToOne(targetEntity: ShopRental::class), ORM\JoinColumn(nullable: true)] private ?ShopRental $rental = null;
    #[ORM\ManyToOne(targetEntity: ShopCoupon::class), ORM\JoinColumn(nullable: true)] private ?ShopCoupon $coupon = null;
    #[ORM\Column(length: 64, nullable: true)] private ?string $requestKey = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $renewalFor = null;
    /** @var array<string, int|string|bool> */
    #[ORM\Column(type: 'json', nullable: true)] private ?array $configuration = null;
    #[ORM\Column(length: 120, nullable: true)] private ?string $processingError = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $lastAttemptAt = null;
    /** @param array<string, int|string|bool> $configuration */
    public function checkout(?Invoice $invoice, array $configuration, string $key, ?ShopCoupon $coupon = null, ?ShopRental $rental = null, ?\DateTimeImmutable $renewalFor = null): void
    {
        if (null !== $this->requestKey) {
            throw new \LogicException('Order checkout is immutable.');
        }
        $this->invoice = $invoice;
        $this->configuration = $configuration;
        $this->requestKey = $key;
        $this->coupon = $coupon;
        $this->rental = $rental;
        $this->renewalFor = $renewalFor;
    }

    public function getInvoice(): ?Invoice
    {
        return $this->invoice;
    }

    public function getRental(): ?ShopRental
    {
        return $this->rental;
    }

    public function setRental(ShopRental $rental): void
    {
        $this->rental = $rental;
    }

    public function getCoupon(): ?ShopCoupon
    {
        return $this->coupon;
    }

    public function releaseCoupon(): void
    {
        $this->coupon = null;
    }

    /** @return array<string, int|string|bool> */
    public function getConfiguration(): array
    {
        return $this->configuration ?? [];
    }

    public function getProcessingError(): ?string
    {
        return $this->processingError;
    }

    public function getLastAttemptAt(): ?\DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function attempted(?string $error = null): void
    {
        $this->lastAttemptAt = new \DateTimeImmutable();
        $this->processingError = $error;
    }

    public function __construct(
        User $customer,
        ShopProduct $product,
        ShopOrderType $type,
        ShopOrderStatus $status,
        int $months,
        int $unitPriceCents,
        int $totalPriceCents,
        ?Instance $instance = null,
    ) {
        $this->customer = $customer;
        $this->product = $product;
        $this->type = $type;
        $this->status = $status;
        $this->months = $months;
        $this->unitPriceCents = $unitPriceCents;
        $this->totalPriceCents = $totalPriceCents;
        $this->instance = $instance;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): User
    {
        return $this->customer;
    }

    public function getProduct(): ShopProduct
    {
        return $this->product;
    }

    public function getInstance(): ?Instance
    {
        return $this->instance;
    }

    public function setInstance(?Instance $instance): void
    {
        $this->instance = $instance;
    }

    public function getType(): ShopOrderType
    {
        return $this->type;
    }

    public function getStatus(): ShopOrderStatus
    {
        return $this->status;
    }

    public function setStatus(ShopOrderStatus $status): void
    {
        $this->status = $status;
    }

    public function getMonths(): int
    {
        return $this->months;
    }

    public function getUnitPriceCents(): int
    {
        return $this->unitPriceCents;
    }

    public function getTotalPriceCents(): int
    {
        return $this->totalPriceCents;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
