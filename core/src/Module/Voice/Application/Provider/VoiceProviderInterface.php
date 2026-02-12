<?php

declare(strict_types=1);

namespace App\Module\Voice\Application\Provider;

use App\Module\Core\Domain\Entity\VoiceInstance;

interface VoiceProviderInterface
{
    public function supports(string $providerType): bool;

    public function query(VoiceInstance $instance): VoiceQueryResult;
}
