<?php

declare(strict_types=1);

namespace App\Dto\Ts3;

final class CreateVirtualServerDto
{
    public function __construct(
        public string $name,
        public ?int $voicePort = null,
        public ?int $filetransferPort = null,
    ) {
    }
}
