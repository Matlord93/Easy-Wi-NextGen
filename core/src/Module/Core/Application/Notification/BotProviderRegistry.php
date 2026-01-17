<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Notification;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class BotProviderRegistry
{
    /**
     * @var array<string, BotProviderInterface>
     */
    private array $providers = [];

    /**
     * @param iterable<BotProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(BotProviderInterface::class)]
        iterable $providers,
    ) {
        foreach ($providers as $provider) {
            $this->providers[$provider->getName()] = $provider;
        }
    }

    public function get(string $name): ?BotProviderInterface
    {
        return $this->providers[$name] ?? null;
    }

    /**
     * @return array<string, BotProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }
}
