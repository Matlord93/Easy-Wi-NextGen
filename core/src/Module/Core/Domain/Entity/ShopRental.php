<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\ShopRentalRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ShopRentalRepository::class)]
#[ORM\Table(name: 'shop_rentals')]
class ShopRental
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

    #[ORM\OneToOne(targetEntity: Instance::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Instance $instance;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        User $customer,
        ShopProduct $product,
        Instance $instance,
        \DateTimeImmutable $expiresAt,
    ) {
        $this->customer = $customer;
        $this->product = $product;
        $this->instance = $instance;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
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

    public function getInstance(): Instance
    {
        return $this->instance;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function extend(int $months): void
    {
        $months = max(1, $months);
        $base = $this->expiresAt > new \DateTimeImmutable() ? $this->expiresAt : new \DateTimeImmutable();
        $this->expiresAt = $base->modify(sprintf('+%d months', $months));
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
