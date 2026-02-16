<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Query;

final class InstanceQuerySpec
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        private readonly bool $supported,
        private readonly ?string $type,
        private readonly ?string $host,
        private readonly ?int $port,
        private readonly int $timeoutMs,
        private readonly array $extra = [],
    ) {
    }

    public static function unsupported(): self
    {
        return new self(false, null, null, null, 0);
    }

    public function isSupported(): bool
    {
        return $this->supported;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function getHost(): ?string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getTimeoutMs(): int
    {
        return $this->timeoutMs;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtra(): array
    {
        return $this->extra;
    }

    /**
     * @return array{type: ?string, host: ?string, port: ?int}
     */
    public function toSafeArray(): array
    {
        return [
            'type' => $this->type,
            'host' => $this->host,
            'port' => $this->port,
        ];
    }
}
