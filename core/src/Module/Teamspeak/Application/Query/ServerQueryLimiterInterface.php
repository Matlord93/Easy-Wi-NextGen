<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Query;

interface ServerQueryLimiterInterface
{
    public function allow(string $cacheKey, int $baseDelaySeconds, int $maxDelaySeconds): ServerQueryLimiterResult;

    public function reset(string $cacheKey): void;
}
