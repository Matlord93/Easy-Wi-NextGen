<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class SymfonyLimiterFactoryAdapter implements LimiterFactoryInterface
{
    public function __construct(private readonly RateLimiterFactory $factory)
    {
    }

    public function create(?string $key = null): LimiterInterface
    {
        return $this->factory->create($key);
    }
}
