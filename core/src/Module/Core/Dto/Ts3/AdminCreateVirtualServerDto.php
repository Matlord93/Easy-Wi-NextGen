<?php

declare(strict_types=1);

namespace App\Module\Core\Dto\Ts3;

use App\Module\Core\Dto\VirtualServerAdminCreateDtoInterface;
use App\Module\Core\Dto\VirtualServerAdminCreateDtoTrait;

final class AdminCreateVirtualServerDto implements VirtualServerAdminCreateDtoInterface
{
    use VirtualServerAdminCreateDtoTrait;

    public function __construct(
        public int $customerId = 0,
        public int $nodeId = 0,
        public string $name = '',
        public ?int $voicePort = null,
        public ?int $filetransferPort = null,
    ) {
    }
}
