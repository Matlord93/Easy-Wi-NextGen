<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\ShopCouponRepository;
use Doctrine\ORM\Mapping as ORM;

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
        $this->code = strtoupper(trim($code));
        if (1 !== preg_match('/^[A-Z0-9][A-Z0-9_-]{2,39}$/', $this->code)) {
            throw new \InvalidArgumentException('Invalid coupon code.');
        }
        if (!in_array($discountType, ['percent', 'fixed'], true) || $discountValue < 1 || ('percent' === $discountType && $discountValue > 100)) {
            throw new \InvalidArgumentException('Invalid coupon discount.');
        }
    }

    public function getCode(): string { return $this->code; }
    public function isAvailable(\DateTimeImmutable $now): bool
    {
        return $this->active
            && (null === $this->validFrom || $now >= $this->validFrom)
            && (null === $this->validUntil || $now <= $this->validUntil)
            && (null === $this->maximumUses || $this->usedCount < $this->maximumUses);
    }
    public function discountFor(int $subtotalCents, \DateTimeImmutable $now): int
    {
        if ($subtotalCents < $this->minimumCents || !$this->isAvailable($now)) { return 0; }
        $discount = 'percent' === $this->discountType
            ? intdiv($subtotalCents * $this->discountValue, 100)
            : $this->discountValue;
        return min($subtotalCents, $discount);
    }
    public function redeem(\DateTimeImmutable $now): void
    {
        if (!$this->isAvailable($now)) { throw new \DomainException('Coupon is not available.'); }
        ++$this->usedCount;
    }
}
