<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

final class BackupRun
{
    public function __construct(
        private readonly string $runId,
        private readonly string $planId,
        private readonly string $status,
        private readonly string $archivePath,
        private readonly int $sizeBytes,
        private readonly string $checksumSha256,
        private readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
        private readonly bool $restored = false,
    ) {
    }

    public function runId(): string
    {
        return $this->runId;
    }
    public function planId(): string
    {
        return $this->planId;
    }
    public function status(): string
    {
        return $this->status;
    }
    public function archivePath(): string
    {
        return $this->archivePath;
    }
    public function sizeBytes(): int
    {
        return $this->sizeBytes;
    }
    public function checksumSha256(): string
    {
        return $this->checksumSha256;
    }
    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
    public function restored(): bool
    {
        return $this->restored;
    }
}
