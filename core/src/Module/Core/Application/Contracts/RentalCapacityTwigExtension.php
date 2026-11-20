<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Module\Core\Domain\Entity\ShopProduct;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** @psalm-import-type Readiness from RentalCapacity */
final class RentalCapacityTwigExtension extends AbstractExtension
{
    public function __construct(private readonly RentalCapacity $capacity)
    {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('rental_availability', fn (ShopProduct $product): string => $this->capacity->status($product)),
            new TwigFunction('rental_readiness', [$this, 'inspect']),
        ];
    }

    /**
     * @param array<string,int|string|bool>|null $quote
     *
     * @return Readiness
     */
    public function inspect(ShopProduct $product, ?array $quote = null): array
    {
        return $this->capacity->inspect($product, $quote);
    }
}
