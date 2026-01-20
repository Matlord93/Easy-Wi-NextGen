<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Dto\Ts3\AdminCreateVirtualServerDto as Ts3AdminCreateVirtualServerDto;
use App\Module\Core\Dto\Ts3\CreateVirtualServerDto as Ts3CreateVirtualServerDto;
use App\Module\Core\Dto\Ts6\AdminCreateVirtualServerDto as Ts6AdminCreateVirtualServerDto;
use App\Module\Core\Dto\Ts6\CreateVirtualServerDto as Ts6CreateVirtualServerDto;

final class VirtualServerDtoFactory
{
    public function createTs3(Ts3AdminCreateVirtualServerDto $dto): Ts3CreateVirtualServerDto
    {
        return new Ts3CreateVirtualServerDto($dto->name, $dto->voicePort, $dto->filetransferPort);
    }

    public function createTs6(Ts6AdminCreateVirtualServerDto $dto): Ts6CreateVirtualServerDto
    {
        return new Ts6CreateVirtualServerDto($dto->name, $dto->slots, $dto->voicePort);
    }
}
