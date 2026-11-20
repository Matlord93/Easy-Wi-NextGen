<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Module\Core\Domain\Entity\ShopContract;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class ContractResources
{
    /** @param iterable<ContractResourceProvider> $providers */
    public function __construct(#[AutowireIterator('app.contract_resource')] private iterable $providers)
    {
    }

    public function for(ShopContract $contract): ContractResourceProvider
    {
        foreach ($this->providers as $provider) {
            if (in_array($contract->getKind(), $provider->kinds(), true)) {
                return $provider;
            }
        }
        throw new \LogicException('No rental provider is registered.');
    }
}
