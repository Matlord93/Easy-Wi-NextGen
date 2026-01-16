<?php

declare(strict_types=1);

namespace App\Dto\Ts3;

final class AdminCreateVirtualServerDto
{
    public function __construct(
        public int $customerId = 0,
        public int $nodeId = 0,
        public string $name = '',
        public ?int $voicePort = null,
        public ?int $filetransferPort = null,
    ) {
    }
}
