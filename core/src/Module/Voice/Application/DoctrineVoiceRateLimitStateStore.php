<?php

declare(strict_types=1);

namespace App\Module\Voice\Application;

use App\Module\Core\Domain\Entity\VoiceNode;
use App\Module\Core\Domain\Entity\VoiceRateLimitState;
use App\Repository\VoiceRateLimitStateRepository;

final class DoctrineVoiceRateLimitStateStore implements VoiceRateLimitStateStoreInterface
{
    public function __construct(private readonly VoiceRateLimitStateRepository $repository)
    {
    }

    public function findOneByNodeAndProvider(VoiceNode $node, string $providerType): ?VoiceRateLimitState
    {
        return $this->repository->findOneByNodeAndProvider($node, $providerType);
    }
}
