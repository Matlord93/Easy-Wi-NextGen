<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotRuntimeEvent;

interface MusicbotRuntimeEventServiceInterface
{
    /** @param array<string, mixed> $context */
    public function record(MusicbotInstance $instance, string $type, string $level = 'info', string $message = '', array $context = []): MusicbotRuntimeEvent;
}
