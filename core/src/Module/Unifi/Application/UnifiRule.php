<?php

declare(strict_types=1);

namespace App\Module\Unifi\Application;

final class UnifiRule
{
    public function __construct(
        private readonly string $name,
        private readonly string $protocol,
        private readonly int $port,
        private readonly string $targetIp,
        private readonly int $targetPort,
        private readonly bool $enabled,
        private readonly string $type,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getProtocol(): string
    {
        return $this->protocol;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getTargetIp(): string
    {
        return $this->targetIp;
    }

    public function getTargetPort(): int
    {
        return $this->targetPort;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'name' => $this->name,
            'proto' => $this->protocol,
            'dst_port' => $this->port,
            'fwd' => $this->targetIp,
            'fwd_port' => $this->targetPort,
            'enabled' => $this->enabled,
        ];
    }
}
