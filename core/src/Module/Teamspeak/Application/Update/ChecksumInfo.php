<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Update;

final class ChecksumInfo
{
    public function __construct(
        public readonly ?string $algorithm,
        public readonly ?string $value,
        public readonly ?string $source = null,
    ) {}

    public function isAvailable(): bool
    {
        return $this->algorithm !== null && $this->value !== null && $this->value !== '';
    }
}
