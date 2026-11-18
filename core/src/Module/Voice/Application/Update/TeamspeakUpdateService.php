<?php

declare(strict_types=1);

namespace App\Module\Voice\Application\Update;

use App\Module\Core\Domain\Entity\Ts6Instance;
use Doctrine\ORM\EntityManagerInterface;

final class TeamspeakUpdateService
{
    /** @param iterable<TeamspeakUpdateProviderInterface> $providers */
    public function __construct(private readonly iterable $providers, private readonly EntityManagerInterface $entityManager)
    {
    }

    public function checkTs6(Ts6Instance $instance): UpdateResult
    {
        $normalizedInstalled = TeamspeakVersionNormalizer::normalize($instance->getInstalledVersion() ?? '0.0.0') ?? '0.0.0';
        if ($instance->getInstalledVersion() !== $normalizedInstalled) {
            $instance->setInstalledVersion($normalizedInstalled);
        }
        $provider = $this->provider('ts6');
        $result = $provider->checkForUpdates($normalizedInstalled, $instance->getPlatformOs() ?? 'linux', $instance->getPlatformArch() ?? 'amd64', $instance->getUpdateChannel());
        $instance->setLastUpdateCheckAt(new \DateTimeImmutable());
        $instance->setAvailableVersion($result->availableVersion);
        $this->entityManager->flush();
        return $result;
    }

    private function provider(string $type): TeamspeakUpdateProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($type)) { return $provider; }
        }
        throw new \RuntimeException('No update provider for '.$type);
    }
}
