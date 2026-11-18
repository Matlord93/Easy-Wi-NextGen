<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class PaymentProviderRegistry
{
    /**
     * @var array<string, PaymentProviderInterface>
     */
    private array $providers = [];

    /**
     * @param iterable<PaymentProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(PaymentProviderInterface::class)]
        iterable $providers,
    ) {
        foreach ($providers as $provider) {
            $this->providers[$provider->getName()] = $provider;
        }
    }

    public function get(string $name): ?PaymentProviderInterface
    {
        if (isset($this->providers[$name])) {
            return $this->providers[$name];
        }
        foreach ($this->providers as $provider) {
            if (in_array($name, $provider->supportedMethods(), true)) {
                return $provider;
            }
        }
        return null;
    }

    /**
     * @return array<string, PaymentProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }
}
