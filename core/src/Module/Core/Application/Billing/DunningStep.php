<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

final class DunningStep
{
    public function __construct(
        public readonly int $level,
        public readonly int $feeCents,
        public readonly int $graceDays,
    ) {
    }
}
