<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Update;

final class ChecksumVerificationResult
{
    public function __construct(
        public readonly bool $verified,
        public readonly bool $missing,
        public readonly ?string $actual = null,
        public readonly ?string $message = null,
    ) {}
}
