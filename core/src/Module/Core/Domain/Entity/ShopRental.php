<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\ShopRentalRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Doctrine hydrates and proxies this entity; Twig also calls its public accessors.
 *
 * @psalm-api
 */
#[ORM\Entity(repositoryClass: ShopRentalRepository::class)]
#[ORM\Table(name: 'shop_rentals')]
#[ORM\Index(name: 'idx_shop_rental_renewal', columns: ['auto_renew', 'expires_at'])]
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

    #[ORM\Column(options: ['default' => false])] private bool $autoRenew = false;
    /** @var array<string, int|string|bool> */
    #[ORM\Column(type: 'json', nullable: true)] private ?array $renewalConfiguration = null;
    public function isAutoRenew(): bool
    {
        return $this->autoRenew;
    }

    public function cancelSubscription(): void
    {
        $this->autoRenew = false;
        $this->touch();
    }

    /** @param array<string, int|string|bool> $configuration */
    public function configureSubscription(array $configuration, bool $autoRenew): void
    {
        $this->renewalConfiguration = $configuration;
        $this->autoRenew = $autoRenew;
    }

    /** @return array<string, int|string|bool> */
    public function getRenewalConfiguration(): array
    {
        return $this->renewalConfiguration ?? [];
    }

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
        $this->expiresAt = \App\Module\Core\Domain\ValueObject\BillingPeriod::addMonths($base, $months);
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
