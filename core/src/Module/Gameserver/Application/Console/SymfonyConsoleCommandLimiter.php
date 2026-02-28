<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Console;

use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class SymfonyConsoleCommandLimiter implements ConsoleCommandLimiterInterface
{
    public function __construct(private RateLimiterFactory $consoleLimiter)
    {
    }

    public function consume(string $key): bool
    {
        return $this->consoleLimiter->create($key)->consume(1)->isAccepted();
    }
}
