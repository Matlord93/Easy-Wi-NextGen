<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use Symfony\Component\RateLimiter\LimiterInterface;

interface LimiterFactoryInterface
{
    public function create(?string $key = null): LimiterInterface;
}
