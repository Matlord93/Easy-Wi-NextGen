<?php

declare(strict_types=1);

namespace App\Dto\Ts6;

final class CreateVirtualServerDto
{
    public function __construct(
        public string $name = '',
        public int $slots = 32,
        public ?int $voicePort = null,
    ) {
    }
}
