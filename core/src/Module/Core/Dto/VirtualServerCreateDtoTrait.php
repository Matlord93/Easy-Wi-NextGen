<?php

declare(strict_types=1);

namespace App\Module\Core\Dto;

trait VirtualServerCreateDtoTrait
{
    public function getName(): string
    {
        return $this->name;
    }

    public function getVoicePort(): ?int
    {
        return $this->voicePort;
    }
}
