<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\ShopCouponRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Doctrine hydrates and proxies this entity; Twig also calls its public accessors.
 *
 * @psalm-api
 */
#[ORM\Entity(repositoryClass: ShopCouponRepository::class)]
#[ORM\Table(name: 'shop_coupons')]
#[ORM\UniqueConstraint(name: 'uniq_shop_coupon_site_code', columns: ['site_id', 'code'])]
class ShopCoupon
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\Column] private int $usedCount = 0;
    public function __construct(
        #[ORM\Column] private int $siteId,
        #[ORM\Column(length: 40)] private string $code,
        #[ORM\Column(length: 10)] private string $discountType,
        #[ORM\Column] private int $discountValue,
        #[ORM\Column] private int $minimumCents = 0,
        #[ORM\Column(nullable: true)] private ?int $maximumUses = null,
        #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $validFrom = null,
        #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $validUntil = null,
        #[ORM\Column] private bool $active = true,
    ) {
        if ($minimumCents < 0 || (null !== $maximumUses && $maximumUses < 1) || (null !== $validFrom && null !== $validUntil && $validFrom > $validUntil)) {
            throw new \InvalidArgumentException('Invalid coupon limits.');
        }
        $this->code = strtoupper(trim($code));
        if (1 !== preg_match('/^[A-Z0-9][A-Z0-9_-]{2,39}$/', $this->code)) {
            throw new \InvalidArgumentException('Invalid coupon code.');
        }
        if (!in_array($discountType, ['percent', 'fixed'], true) || $discountValue < 1 || ('percent' === $discountType && $discountValue > 100)) {
            throw new \InvalidArgumentException('Invalid coupon discount.');
        }
    }

    /** Existing code and redemption count remain stable when the offer is edited. */
    public function configure(string $type, int $value, int $minimum, ?int $maximum, ?\DateTimeImmutable $from, ?\DateTimeImmutable $until, bool $active): void
    {
        $validated = new self($this->siteId, $this->code, $type, $value, $minimum, $maximum, $from, $until, $active);
        $this->discountType = $validated->discountType;
        $this->discountValue = $validated->discountValue;
        $this->minimumCents = $validated->minimumCents;
        $this->maximumUses = $validated->maximumUses;
        $this->validFrom = $validated->validFrom;
        $this->validUntil = $validated->validUntil;
        $this->active = $validated->active;
    }

    public function getMinimumCents(): int
    {
        return $this->minimumCents;
    }

    public function getValidFrom(): ?\DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSiteId(): int
    {
        return $this->siteId;
    }

    public function getDiscountType(): string
    {
        return $this->discountType;
    }

    public function getDiscountValue(): int
    {
        return $this->discountValue;
    }

    public function getUsedCount(): int
    {
        return $this->usedCount;
    }

    public function getMaximumUses(): ?int
    {
        return $this->maximumUses;
    }

    public function getValidUntil(): ?\DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function releaseReservation(): void
    {
        $this->usedCount = max(0, $this->usedCount - 1);
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function isAvailable(\DateTimeImmutable $now): bool
    {
        return $this->active
            && (null === $this->validFrom || $now >= $this->validFrom)
            && (null === $this->validUntil || $now <= $this->validUntil)
            && (null === $this->maximumUses || $this->usedCount < $this->maximumUses);
    }

    public function discountFor(int $subtotalCents, \DateTimeImmutable $now): int
    {
        if ($subtotalCents < $this->minimumCents || !$this->isAvailable($now)) {
            return 0;
        }
        $discount = 'percent' === $this->discountType
            ? intdiv($subtotalCents * $this->discountValue, 100)
            : $this->discountValue;

        return min($subtotalCents, $discount);
    }

    public function redeem(\DateTimeImmutable $now): void
    {
        if (!$this->isAvailable($now)) {
            throw new \DomainException('Coupon is not available.');
        }
        ++$this->usedCount;
    }
}
