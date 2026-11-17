<?php

declare(strict_types=1);

namespace App\Module\Unifi\Application;

final readonly class UnifiSyncClaim
{
    public function __construct(public int $instanceId, public int $revision, public string $token, public int $attempts)
    {
    }
}
