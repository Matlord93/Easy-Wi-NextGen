<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Ddos;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class DdosProviderRegistry
{
    /**
     * @var array<string, DdosProviderInterface>
     */
    private array $providers = [];

    /**
     * @param iterable<DdosProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(DdosProviderInterface::class)]
        iterable $providers,
    ) {
        foreach ($providers as $provider) {
            $this->providers[$provider->getName()] = $provider;
        }
    }

    public function get(string $name): ?DdosProviderInterface
    {
        return $this->providers[$name] ?? null;
    }

    /**
     * @return array<string, DdosProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }
}
