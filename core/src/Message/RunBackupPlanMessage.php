<?php

declare(strict_types=1);

namespace App\Message;

final class RunBackupPlanMessage
{
    public function __construct(
        private readonly string $planId,
        private readonly bool $manual,
        private readonly ?string $idempotencyKey = null,
    ) {
    }

    public function planId(): string
    {
        return $this->planId;
    }
    public function manual(): bool
    {
        return $this->manual;
    }
    public function idempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }
}
