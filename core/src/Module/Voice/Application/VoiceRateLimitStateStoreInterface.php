<?php

declare(strict_types=1);

namespace App\Module\Voice\Application;

use App\Module\Core\Domain\Entity\VoiceNode;
use App\Module\Core\Domain\Entity\VoiceRateLimitState;

interface VoiceRateLimitStateStoreInterface
{
    public function findOneByNodeAndProvider(VoiceNode $node, string $providerType): ?VoiceRateLimitState;
}
