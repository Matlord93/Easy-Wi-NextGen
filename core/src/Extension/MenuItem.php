<?php

declare(strict_types=1);

namespace App\Extension;

final class MenuItem
{
    public function __construct(
        public readonly string $label,
        public readonly string $href,
        public readonly ?string $iconSvg = null,
    ) {
    }
}
