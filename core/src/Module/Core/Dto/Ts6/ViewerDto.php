<?php

declare(strict_types=1);

namespace App\Module\Core\Dto\Ts6;

final class ViewerDto
{
    public function __construct(
        public bool $enabled = true,
        public int $cacheTtlMs = 1500,
        public ?string $domainAllowlist = null,
    ) {
    }
}
