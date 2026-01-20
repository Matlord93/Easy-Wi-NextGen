<?php

declare(strict_types=1);

namespace App\Module\Core\Dto;

trait VirtualServerAdminCreateDtoTrait
{
    public function getCustomerId(): int
    {
        return $this->customerId;
    }

    public function getNodeId(): int
    {
        return $this->nodeId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getVoicePort(): ?int
    {
        return $this->voicePort;
    }
}
