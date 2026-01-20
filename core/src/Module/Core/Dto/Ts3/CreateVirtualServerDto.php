<?php

declare(strict_types=1);

namespace App\Module\Core\Dto\Ts3;

use App\Module\Core\Dto\VirtualServerCreateDtoInterface;
use App\Module\Core\Dto\VirtualServerCreateDtoTrait;

final class CreateVirtualServerDto implements VirtualServerCreateDtoInterface
{
    use VirtualServerCreateDtoTrait;

    public function __construct(
        public string $name,
        public ?int $voicePort = null,
        public ?int $filetransferPort = null,
    ) {
    }
}
