<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

final class BackupPlan
{
    /**
     * @param array<string, scalar|array|null> $options
     */
    public function __construct(
        private readonly string $id,
        private readonly string $module,
        private readonly string $resourceId,
        private readonly BackupStorageTarget $target,
        private readonly RetentionPolicy $retentionPolicy,
        private readonly ?string $cronExpression = null,
        private readonly string $timeZone = 'UTC',
        private readonly array $options = [],
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }
    public function module(): string
    {
        return $this->module;
    }
    public function resourceId(): string
    {
        return $this->resourceId;
    }
    public function target(): BackupStorageTarget
    {
        return $this->target;
    }
    public function retentionPolicy(): RetentionPolicy
    {
        return $this->retentionPolicy;
    }
    public function cronExpression(): ?string
    {
        return $this->cronExpression;
    }
    public function timeZone(): string
    {
        return $this->timeZone;
    }

    /** @return array<string, scalar|array|null> */
    public function options(): array
    {
        return $this->options;
    }
}
