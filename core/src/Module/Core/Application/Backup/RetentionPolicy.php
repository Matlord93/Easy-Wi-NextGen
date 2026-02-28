<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

final class RetentionPolicy
{
    public function __construct(
        private readonly int $keepCount,
        private readonly int $keepDays,
    ) {
    }

    public function keepCount(): int
    {
        return max(0, $this->keepCount);
    }

    public function keepDays(): int
    {
        return max(0, $this->keepDays);
    }
}
