<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Update;

use App\Module\Core\Domain\Entity\Ts3Instance;
use App\Module\Core\Domain\Entity\Ts6Instance;
use App\Module\Core\Domain\Entity\User;
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
        $result = $provider->checkForUpdates($normalizedInstalled, $instance->getPlatformOs() ?? 'linux', $instance->getPlatformArch() ?? 'amd64', $instance->getUpdateChannel() ?? 'stable');
        $instance->setLastUpdateCheckAt(new \DateTimeImmutable());
        $instance->setAvailableVersion($result->availableVersion);
        $this->entityManager->flush();
        return $result;
    }

    public function checkTs3(Ts3Instance $instance): UpdateResult
    {
        return $this->provider('ts3')->checkForUpdates($instance->getInstalledVersion() ?? 'unknown', $instance->getPlatformOs() ?? 'linux', $instance->getPlatformArch() ?? 'amd64', $instance->getUpdateChannel() ?? 'stable');
    }

    private function provider(string $type): TeamspeakUpdateProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($type)) { return $provider; }
        }
        throw new \RuntimeException('No update provider for '.$type);
    }
}
