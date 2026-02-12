<?php

declare(strict_types=1);

namespace App\Module\Voice\Application\Provider;

final class VoiceProviderRegistry
{
    /** @param iterable<VoiceProviderAdapter> $adapters */
    public function __construct(private readonly iterable $adapters)
    {
    }

    public function forType(string $providerType): VoiceProviderAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($providerType)) {
                return $adapter;
            }
        }

        throw new \RuntimeException(sprintf('Unsupported voice provider "%s".', $providerType));
    }
}
