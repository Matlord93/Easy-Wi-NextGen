<?php

declare(strict_types=1);

namespace App\Module\Core\Dto;

interface VirtualServerCreateDtoInterface
{
    public function getName(): string;

    public function getVoicePort(): ?int;
}
