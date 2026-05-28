<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

final class PrivacyGdprBackgroundJobResult
{
    /** @param array<string,int> $counts */
    public function __construct(
        public readonly array $counts,
        public readonly string $message,
    ) {
    }

    public function totalAffected(): int
    {
        return array_sum($this->counts);
    }
}
