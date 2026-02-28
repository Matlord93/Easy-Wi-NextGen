<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

final class BackupStorageTarget
{
    /**
     * @param array<string, scalar|array|null> $config
     * @param array<string, scalar|array|null> $secrets
     */
    public function __construct(
        private readonly string $type,
        private readonly string $label,
        private readonly array $config,
        private readonly array $secrets = [],
    ) {
    }

    public function type(): string
    {
        return $this->type;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** @return array<string, scalar|array|null> */
    public function config(): array
    {
        return $this->config;
    }

    /** @return array<string, scalar|array|null> */
    public function secrets(): array
    {
        return $this->secrets;
    }
}
