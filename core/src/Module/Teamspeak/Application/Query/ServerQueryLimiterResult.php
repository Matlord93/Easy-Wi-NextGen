<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Query;

final class ServerQueryLimiterResult
{
    public function __construct(
        private readonly bool $allowed,
        private readonly int $retryAfterSeconds,
    ) {
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
