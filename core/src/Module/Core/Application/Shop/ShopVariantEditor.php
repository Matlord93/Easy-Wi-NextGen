<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Shop;

use App\Module\Core\Application\Billing\BillingAmount;
use App\Module\Core\Domain\Entity\ShopProduct;
use App\Module\Core\Domain\Entity\ShopProductVariant;
use App\Repository\ShopProductVariantRepository;

final readonly class ShopVariantEditor
{
    public function __construct(private ShopProductVariantRepository $variants)
    {
    }

    /** @param array<string,string> $input */
    public function save(ShopProduct $product, ?ShopProductVariant $variant, array $input, bool $legacyResources = false): ShopProductVariant
    {
        if (null !== $variant && $variant->getProduct()->getId() !== $product->getId()) {
            throw new \InvalidArgumentException('Die Variante gehört nicht zu diesem Produkt.');
        }
        $sku = strtoupper(trim($input['sku'] ?? $variant?->getSku() ?? ''));
        if (null !== $variant && $sku !== $variant->getSku()) {
            throw new \InvalidArgumentException('Die SKU einer vorhandenen Variante kann nicht geändert werden.');
        }
        if (null === $variant && null !== $this->variants->findOneBy(['sku' => $sku])) {
            throw new \InvalidArgumentException('Diese SKU wird bereits verwendet.');
        }
        $name = trim($input['name'] ?? '');
        $price = BillingAmount::parse($input['price'] ?? '');
        if ($price > 10_000_000) {
            throw new \InvalidArgumentException('Der Tarif überschreitet das unterstützte Maximum.');
        }
        $kind = $product->getKind()->value;
        $compute = in_array($kind, ['gameserver', 'kvm'], true);
        $cpu = $compute || $legacyResources ? $this->number($input, 'cpu') : ($variant?->getCpuLimit() ?? $product->getCpuLimit());
        $ram = $compute || $legacyResources ? $this->number($input, 'ram') : ($variant?->getRamLimit() ?? $product->getRamLimit());
        $disk = $compute || 'webspace' === $kind || $legacyResources ? $this->number($input, 'disk') : ($variant?->getDiskLimit() ?? $product->getDiskLimit());
        if ('kvm' === $kind && ($cpu > 256 || $ram > 1024 || $disk > 2048)) {
            throw new \InvalidArgumentException('KVM-Varianten unterstützen höchstens 256 CPU-Kerne, 1.024 GiB RAM und 2.048 GiB Speicher.');
        }
        $slots = $variant?->getSlots();
        if (in_array($kind, ['teamspeak3', 'teamspeak6'], true) || $legacyResources) {
            $slots = '' === ($input['slots'] ?? '') ? null : $this->number($input, 'slots');
        }
        $active = filter_var($input['active'] ?? '0', FILTER_VALIDATE_BOOLEAN);
        // Validate every field before changing an already managed entity.
        $validated = new ShopProductVariant($product, $sku, $name, $price, $cpu, $ram, $disk, $active);
        $validated->update($name, $price, $cpu, $ram, $disk, $active);
        $validated->setSlots($slots);
        if (null === $variant) {
            return $validated;
        }
        $variant->update($name, $price, $cpu, $ram, $disk, $active);
        $variant->setSlots($slots);

        return $variant;
    }

    /** @param array<string,string> $input */
    private function number(array $input, string $key): int
    {
        $number = filter_var($input[$key] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2_147_483_647]]);
        if (false === $number) {
            throw new \InvalidArgumentException('Bitte einen gültigen positiven Wert für '.$key.' angeben.');
        }

        return $number;
    }
}
