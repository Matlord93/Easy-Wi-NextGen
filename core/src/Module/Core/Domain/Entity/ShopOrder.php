<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Module\Core\Domain\Enum\ShopOrderStatus;
use App\Module\Core\Domain\Enum\ShopOrderType;
use App\Repository\ShopOrderRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ShopOrderRepository::class)]
#[ORM\Table(name: 'shop_orders')]
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
