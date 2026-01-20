<?php

declare(strict_types=1);

namespace App\Module\Core\Dto;

interface VirtualServerAdminCreateDtoInterface
{
    public function getCustomerId(): int;

    public function getNodeId(): int;

    public function getName(): string;

    public function getVoicePort(): ?int;
}
