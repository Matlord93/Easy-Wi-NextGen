<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\ShopProductVariantRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ShopProductVariantRepository::class)]
#[ORM\Table(name: 'shop_product_variants')]
#[ORM\UniqueConstraint(name: 'uniq_shop_variant_sku', columns: ['sku'])]
class ShopProductVariant
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: ShopProduct::class)] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private ShopProduct $product,
        #[ORM\Column(length: 80)] private string $sku,
        #[ORM\Column(length: 120)] private string $name,
        #[ORM\Column] private int $priceMonthlyCents,
        #[ORM\Column] private int $cpuLimit,
        #[ORM\Column] private int $ramLimit,
        #[ORM\Column] private int $diskLimit,
        #[ORM\Column] private bool $active = true,
    ) {
        if ($priceMonthlyCents < 0 || $cpuLimit < 1 || $ramLimit < 1 || $diskLimit < 1) {
            throw new \InvalidArgumentException('Variant price and resource limits must be positive.');
        }
        if (1 !== preg_match('/^[A-Z0-9][A-Z0-9_-]{1,79}$/', $sku)) {
            throw new \InvalidArgumentException('Invalid product variant SKU.');
        }
    }

    public function getId(): ?int { return $this->id; }
    public function getProduct(): ShopProduct { return $this->product; }
    public function getSku(): string { return $this->sku; }
    public function getName(): string { return $this->name; }
    public function getPriceMonthlyCents(): int { return $this->priceMonthlyCents; }
    public function getCpuLimit(): int { return $this->cpuLimit; }
    public function getRamLimit(): int { return $this->ramLimit; }
    public function getDiskLimit(): int { return $this->diskLimit; }
    public function isActive(): bool { return $this->active; }
}
