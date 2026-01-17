<?php

declare(strict_types=1);

namespace App\Module\Core\Dto\Ts6;

final class AdminCreateVirtualServerDto
{
    public function __construct(
        public int $customerId = 0,
        public int $nodeId = 0,
        public string $name = '',
        public int $slots = 32,
        public ?int $voicePort = null,
    ) {
    }
}
