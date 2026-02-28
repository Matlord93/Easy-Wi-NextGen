<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Adapter;

final class RestoreReport
{
    public function __construct(
        private readonly bool $dryRun,
        private readonly bool $success,
        private readonly string $message,
    ) {
    }

    public function dryRun(): bool
    {
        return $this->dryRun;
    }
    public function success(): bool
    {
        return $this->success;
    }
    public function message(): string
    {
        return $this->message;
    }
}
